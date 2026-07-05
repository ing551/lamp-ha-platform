# Zabbix 6.4 全栈监控部署文档

## 项目概述

在七节点 LAMP 高可用架构基础上，新增 Zabbix Server（192.168.200.131），实现对全部 8 台服务器的集中监控。监控范围涵盖系统资源（CPU、内存、磁盘、网络）以及各核心服务（Nginx、MySQL、Redis、PHP-FPM）的运行状态。

### 架构一览

| IP | 主机名 | 角色 | 被监控 |
|----|--------|------|--------|
| 192.168.200.131 | zabbix-server | Zabbix 监控服务器 | 自身 |
| 192.168.200.123 | web1 | Web 节点 1 | ✓ |
| 192.168.200.126 | web2 | Web 节点 2 | ✓ |
| 192.168.200.124 | mysql1 | MySQL 数据库 | ✓ |
| 192.168.200.127 | redis1 | Redis 缓存 | ✓ |
| 192.168.200.125 | nfs1 | NFS 共享存储 | ✓ |
| 192.168.200.128 | lb1 | 负载均衡主节点 | ✓ |
| 192.168.200.129 | lb2 | 负载均衡备节点 | ✓ |
| 192.168.200.130 | ansible | Ansible 控制机 | ✓ |

---

## 第一部分：Zabbix Server 部署（131）

### 1.1 安装 Zabbix 仓库和 Server 包

```bash
rpm -Uvh https://repo.zabbix.com/zabbix/6.4/rhel/9/x86_64/zabbix-release-6.4-1.el9.noarch.rpm
dnf install -y zabbix-server-mysql zabbix-web-mysql zabbix-nginx-conf zabbix-sql-scripts zabbix-selinux-policy zabbix-agent
```

**为什么先 rpm 再 dnf？**

`rpm -Uvh` 只负责安装 Zabbix 官方仓库配置文件（`/etc/yum.repos.d/zabbix.repo`）。Zabbix 不在 Rocky Linux 默认源里，必须先添加仓库，后续 `dnf install` 才能找到这些包。

**6 个包的作用：**

| 包名 | 作用 | 与其他步骤的关联 |
|------|------|------------------|
| `zabbix-server-mysql` | Zabbix 服务端主程序，负责采集数据、触发告警 | 依赖步骤 1.2 的 MariaDB 存数据 |
| `zabbix-web-mysql` | Zabbix 前端 Web 界面（PHP 写的） | 依赖 Nginx+PHP-FPM 来运行 |
| `zabbix-nginx-conf` | 提供 Nginx 配置文件模板，让前端通过浏览器访问 | 步骤 1.5 要修改它的端口和域名 |
| `zabbix-sql-scripts` | 包含建表 SQL（`server.sql.gz`） | 步骤 1.4 用它导入表结构 |
| `zabbix-selinux-policy` | SELinux 策略，让 Zabbix 在 SELinux 启用时也能正常工作 | 安全相关，即使关了 SELinux 也建议装 |
| `zabbix-agent` | 监控 Zabbix Server 自身 | 和第二部分被控端 Agent 是同一个东西 |

### 1.2 安装并启动 MariaDB

```bash
dnf install -y mariadb-server mariadb
systemctl start mariadb && systemctl enable mariadb
```

**为什么 Zabbix 要单独的 MySQL？**

Zabbix Server 需要数据库存储所有监控数据——主机配置、采集的指标值、告警记录、用户配置等。这里在 131 本地装了一个 MariaDB 专门给 Zabbix 用，**不和 124 上的业务 MySQL 混在一起**。

原因：

- **性能隔离**：监控数据写入量很大（每台机器几十个指标，每几秒采集一次），和业务库混在一起会互相拖累
- **故障隔离**：如果业务 MySQL 被压垮，监控系统也跟着挂，那就无法知道业务出了什么问题。监控系统必须有独立的命脉
- **版本独立**：Zabbix 对 MySQL 版本有特定要求，独立部署可以各自升级互不影响

### 1.3 创建 Zabbix 数据库和用户

```sql
CREATE DATABASE zabbix CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER ''zabbix''@''localhost'' IDENTIFIED BY ''zabbix123'';
GRANT ALL ON zabbix.* TO ''zabbix''@''localhost'';
FLUSH PRIVILEGES;
```

| 语句 | 说明 | 与其他步骤的关联 |
|------|------|------------------|
| `CREATE DATABASE zabbix` | 创建空库，`utf8mb4` 支持 emoji 等多字节字符 | 步骤 1.4 向其中导入表结构 |
| `CREATE USER ''zabbix''@''localhost''` | 创建 zabbix 用户，只允许本地连接（安全） | 步骤 1.5 在 server 配置文件中填入此用户的密码 |
| `GRANT ALL ON zabbix.*` | 给 zabbix 用户对 zabbix 库的全部权限 | Zabbix Server 需要对库做增删改查 |
| `FLUSH PRIVILEGES` | 刷新 MySQL 权限表 | 让授权立即生效 |

### 1.4 导入 Zabbix 表结构

```bash
zcat /usr/share/zabbix-sql-scripts/mysql/server.sql.gz | mysql -u zabbix -pzabbix123 zabbix
```

**这步在干什么？**

步骤 1.3 创建的是空库，里面一张表都没有。`server.sql.gz` 是 Zabbix 官方提供的建表脚本压缩包，包含上百张表的 `CREATE TABLE` 语句——主机表、监控项表、触发器表、告警表等等。

`zcat` 不解压直接读取 `.gz` 内容，通过管道 `|` 丢给 `mysql` 客户端执行。效果等同于手动执行几百条 SQL。

**为什么是管道而不是先解压再导入？**

一步到位，不需要临时占用额外磁盘空间，效率更高。

### 1.5 配置 Zabbix Server 数据库密码

```bash
sed -i ''s/# DBPassword=/DBPassword=zabbix123/'' /etc/zabbix/zabbix_server.conf
```

Zabbix Server 自带配置文件 `/etc/zabbix/zabbix_server.conf`，里面 `DBPassword` 行默认是被注释掉的（`# DBPassword=`）。

这一步去掉注释符，填上步骤 1.3 设置的密码 `zabbix123`。改完之后 Zabbix Server 启动时才能连上数据库。如果不配这一步，Server 无法启动，前端安装向导也会卡在"数据库连接"页面。

### 1.6 配置 Nginx 前端

修改 `/etc/nginx/conf.d/zabbix.conf`，改两个地方：

```
# 改前
#        listen          8080;
#        server_name     example.com;

# 改后
        listen          80;
        server_name     _;
```

| 修改 | 说明 |
|------|------|
| `8080 → 80` | 用标准 HTTP 端口，浏览器直接 `http://IP` 就能访问，不用加端口号 |
| `example.com → _` | `_` 是 Nginx 通配符，匹配任意域名，直接用 IP 就能访问 Zabbix 前端 |

这个配置文件是步骤 1.1 安装 `zabbix-nginx-conf` 时自带的，位于 `/etc/nginx/conf.d/` 下，会被 Nginx 主配置 `include` 加载。

### 1.7 启动全部服务

```bash
systemctl start zabbix-server zabbix-agent nginx php-fpm
systemctl enable zabbix-server zabbix-agent nginx php-fpm
```

**4 个服务的启动顺序没有强依赖**（systemd 会自己处理），但逻辑上：

- `php-fpm` 负责运行 Zabbix 前端的 PHP 代码
- `nginx` 接收浏览器 HTTP 请求，转发给 PHP-FPM
- `zabbix-server` 核心进程，轮询 Agent 采集数据、评估触发器、发送告警
- `zabbix-agent` 监控 Zabbix Server 自身

### 1.8 前端安装向导

浏览器访问 `http://192.168.200.131`，按向导依次操作：

| 步骤 | 操作 | 说明 |
|------|------|------|
| 欢迎页 | 选择语言 → 下一步 | 这里选英语，后面进系统再切中文 |
| 先修条件检查 | 全部绿色 OK → 下一步 | Zabbix 自动检查 PHP 扩展、数据库连接等依赖 |
| 配置数据库连接 | 只填 Password: `zabbix123` | 其他保持默认（localhost、zabbix 库、zabbix 用户） |
| 设置 | 时区选 `Asia/Shanghai` | 否则告警时间是 UTC，对中国人不友好 |
| 安装前汇总 | 确认 → 下一步 | |
| 安装 | 等几秒 → 完成 | 写入配置文件 `/etc/zabbix/web/zabbix.conf.php` |

默认登录：用户名 `Admin`，密码 `zabbix`（注意 A 大写）。

---

## 第二部分：被控端 Agent 部署（123 - 130）

### 2.1 Agent 的作用

Zabbix Agent 是装在被监控机器上的客户端程序，默认监听 10050 端口。它负责：

- 采集本机数据（CPU、内存、磁盘、进程等）
- 响应 Zabbix Server 的查询请求（被动模式）
- 主动向 Server 推送数据（主动模式）

### 2.2 安装 Agent（Ansible 批量部署）

在 130（Ansible 控制机）上执行：

```bash
# 必须 cd 到 ansible 项目目录，因为 ansible.cfg 里指定了 inventory
cd /root/ansible-lamp

# 第一步：添加 Zabbix 仓库
ansible all -m shell -a "rpm -Uvh https://repo.zabbix.com/zabbix/6.4/rhel/9/x86_64/zabbix-release-6.4-1.el9.noarch.rpm"

# 第二步：安装 agent
ansible all -m dnf -a "name=zabbix-agent state=present"
```

**安装遇到的问题：版本冲突**

web 和 lb 节点（123, 126, 128, 129）之前从 EPEL 源安装了 `zabbix-agent-6.0.46`，和 Zabbix 6.4 Server 不兼容。6.0 版本的 Agent 没有 `/etc/zabbix/zabbix_agentd.conf` 配置文件，导致后续配置失败。

**修复方法：**

```bash
# 删除 EPEL 的 6.0 版，强制从 Zabbix 官方仓库装 6.4 版
ansible web,nginx-lb -m shell -a "dnf remove -y zabbix-agent zabbix-selinux zabbix 2>/dev/null; dnf --disablerepo=''*'' --enablerepo=zabbix install -y zabbix-agent"
```

| 参数 | 说明 |
|------|------|
| `--disablerepo=''*''` | 禁用所有仓库，避免 dnf 从 EPEL/appstream 拉 6.0 版 |
| `--enablerepo=zabbix` | 只开 Zabbix 官方仓库，确保拉 6.4 版 |

### 2.3 配置 Agent 指向 Zabbix Server

```bash
# 被动模式：允许 Server 来拉数据
ansible all -m lineinfile -a "path=/etc/zabbix/zabbix_agentd.conf regexp=''^Server=127.0.0.1'' line=''Server=192.168.200.131''"

# 主动模式：Agent 主动推送数据
ansible all -m lineinfile -a "path=/etc/zabbix/zabbix_agentd.conf regexp=''^ServerActive=127.0.0.1'' line=''ServerActive=192.168.200.131''"
```

**被动模式 vs 主动模式：**

| 模式 | 方向 | 配置项 | 说明 |
|------|------|--------|------|
| 被动模式 | Server → Agent | `Server` | Server 定期向 Agent 发起查询，Agent 返回数据。适合数据量不大的场景 |
| 主动模式 | Agent → Server | `ServerActive` | Agent 主动连接 Server 取回自己该采集哪些项，然后周期推送数据。适合大规模、网络复杂的场景 |

两个都配了，Zabbix 会根据模板中监控项的类型自动选择。

**Zabbix Server 自身（131）的 Agent 不用改**，默认 `Server=127.0.0.1` 即可，因为 Server 和 Agent 在同一台机器上。

### 2.4 启动 Agent

```bash
ansible all -m systemd -a "name=zabbix-agent state=started enabled=yes"
```

---

## 第三部分：添加主机到 Zabbix 前端

### 3.1 操作路径

**Zabbix 前端 → 数据采集 → 主机 → 右上角"创建主机"**

### 3.2 每台主机需要填写的字段

| 字段 | 值 | 说明 |
|------|-----|------|
| 主机名称 | `192.168.200.xxx` | 必须和 IP 对应，Agent 用它来标识自己 |
| 可见名称 | `web1`/`mysql1` 等 | 显示用的别名，方便人看 |
| 模板 | `Linux by Zabbix agent` | 该模板包含 95 个监控项，覆盖 CPU/内存/磁盘/网络/进程等系统指标 |
| 主机组 | `Linux servers` | 分组管理，批量查看 |
| 接口 → 客户端 | IP 填对应地址，端口 `10050` | 这就是 Zabbix Server 连接 Agent 的入口 |

**可用性"绿色"的含义：**

添加主机后，ZBX 标签变绿表示 Zabbix Server 通过 10050 端口成功连接了 Agent，Agent 应答正常。灰色表示还没连上（等一个采集周期，约 1-2 分钟）或网络不通。

### 3.3 8 台主机列表

| 主机名称 | 可见名称 | IP |
|----------|----------|-----|
| 192.168.200.123 | web1 | 192.168.200.123 |
| 192.168.200.124 | mysql1 | 192.168.200.124 |
| 192.168.200.125 | nfs1 | 192.168.200.125 |
| 192.168.200.126 | web2 | 192.168.200.126 |
| 192.168.200.127 | redis1 | 192.168.200.127 |
| 192.168.200.128 | lb1 | 192.168.200.128 |
| 192.168.200.129 | lb2 | 192.168.200.129 |
| 192.168.200.130 | ansible | 192.168.200.130 |

---

## 第四部分：Nginx 服务监控

### 4.1 监控思路

`Linux by Zabbix agent` 模板只能监控到"系统层面有没有 Nginx 进程"和"进程占多少内存"。真正的 Nginx 服务质量需要知道：

- 当前有多少活跃连接？
- 接受了多少请求？
- 有没有连接堆积在 Waiting 状态？
- 每秒处理多少请求（QPS）？

这些数据 Nginx 本身有，但需要通过 `stub_status` 模块暴露出来，Zabbix Agent 再去采集。

### 4.2 启用 Nginx stub_status（4 台：web1, web2, lb1, lb2）

每台创建 `/etc/nginx/conf.d/status.conf`：

```nginx
server {
    listen 127.0.0.1:8080;
    location /nginx_status {
        stub_status;
    }
}
```

**逐行解读：**

| 配置 | 说明 |
|------|------|
| `listen 127.0.0.1:8080` | 只监听本机回环地址。**安全原因**——Nginx 状态页暴露了当前连接数、请求数等信息，不能被外部直接访问 |
| 为什么用 `8080` 而不是 `80` | web 节点 80 端口已被 WordPress 占用；lb 节点 80 端口已被负载均衡占用。用独立端口互不冲突 |
| `stub_status` | Nginx 内置模块 `ngx_http_stub_status_module`，开启后访问指定路径返回纯文本统计数据 |

**访问测试：**

```bash
curl http://127.0.0.1:8080/nginx_status
```

返回示例：

```
Active connections: 1
server accepts handled requests
 44 44 28
Reading: 0 Writing: 1 Waiting: 0
```

| 字段 | 含义 | 监控价值 |
|------|------|----------|
| `Active connections` | 当前活跃连接数 | 突增可能表示攻击或被压垮 |
| `accepts` | 启动以来累计接受的连接 | 看趋势增长 |
| `handled` | 成功处理的连接 | 如果比 accepts 少很多，说明有丢连接 |
| `requests` | 总请求数 | 用来算 QPS |
| `Reading` | 正在读请求头的连接 | |
| `Writing` | 正在写响应的连接 | Writing 高说明后端 PHP 慢或客户端慢 |
| `Waiting` | keep-alive 空闲连接 | 长期高可能是慢客户端 |

**Ansible 批量部署：**

```bash
# 写 status.conf
ansible web,nginx-lb -m copy -a "content='server {
    listen 127.0.0.1:8080;
    location /nginx_status {
        stub_status;
    }
}
' dest=/etc/nginx/conf.d/status.conf owner=root group=root mode=0644"

# 在 nginx.conf 的 http 块中补上 include conf.d/ 目录（之前 Ansible 部署的模板没有这行）
ansible web,nginx-lb -m lineinfile -a "path=/etc/nginx/nginx.conf insertafter=''include /etc/nginx/mime.types;'' line=''    include /etc/nginx/conf.d/*.conf;''"

# 重载并验证
ansible web,nginx-lb -m shell -a "systemctl reload nginx && sleep 1 && curl -s http://127.0.0.1:8080/nginx_status"
```

**遇到的问题：nginx.conf 没有 include conf.d**

Ansible 部署的 Web 节点和 LB 节点的 `nginx.conf` 模板是通过 `template` 模块渲染的，只包含了 WordPress 或负载均衡的配置，没有 `include /etc/nginx/conf.d/*.conf;` 这一行。所以 `status.conf` 虽然写对了位置，nginx -t 也不报错（因为 `status.conf` 本身语法没问题），但 nginx 根本没加载它，8080 端口一直是关的。

用 `lineinfile` 模块在 `include /etc/nginx/mime.types;` 后面追加一行即可。

### 4.3 配置 Zabbix Agent 读取 Nginx 状态

在 4 台机器上创建 `/etc/zabbix/zabbix_agentd.d/nginx.conf`：

```
UserParameter=nginx.status[*],curl -s http://127.0.0.1:8080/nginx_status
```

**`UserParameter` 是什么？**

Zabbix Agent 默认只能采集系统级数据（通过内置 key 如 `system.cpu.load`、`vm.memory.size` 等）。要采集自定义数据（比如 curl Nginx 状态页），需要定义 `UserParameter`。

格式：`UserParameter=<key名称>,<命令>`

- `nginx.status[*]`：定义一个 key，Agent 收到这个名字的查询时执行后面的命令
- `curl -s http://127.0.0.1:8080/nginx_status`：返回 Nginx 状态页的原始文本

**配置文件路径 `/etc/zabbix/zabbix_agentd.d/`：**

Agent 主配置文件 `/etc/zabbix/zabbix_agentd.conf` 里有一行 `Include=/etc/zabbix/zabbix_agentd.d/*.conf`，会自动加载这个目录下所有 `.conf` 文件。这样可以把不同服务的 UserParameter 分文件管理，比全写在主配置里清晰。

**Ansible 推送并重启 Agent：**

```bash
ansible web,nginx-lb -m copy -a "content='UserParameter=nginx.status[*],curl -s http://127.0.0.1:8080/nginx_status
' dest=/etc/zabbix/zabbix_agentd.d/nginx.conf owner=root group=root mode=0644"

ansible web,nginx-lb -m systemd -a "name=zabbix-agent state=restarted"
```

### 4.4 导入 Nginx 监控模板

**为什么需要导入模板？**

Zabbix 内置只有 `Linux by Zabbix agent` 模板。`Nginx by Zabbix agent` 模板需要从 Zabbix 官方仓库下载 YAML 文件导入。该模板约定了：

- 监控项的 key 名称（如 `web.page.get`）
- 数据预处理方式（如正则提取某个数值）
- 这些 key 触发告警的条件（触发器）

**导入路径：** Zabbix 前端 → 数据采集 → 模板 → 右上角"导入" → 选择 `.yaml` 文件 → 导入

**遇到问题：Zabbix Git 仓库需要登录，GitHub 被墙**

最终通过直接手写 YAML 模板文件解决。模板核心定义了 14 个监控项：Active connections、Reading/Writing/Waiting、Requests per second 等。

**模板中与 stub_status 交互的监控项（以 Get stub status page 为例）：**

```
键值: web.page.get["{$NGINX.STUB_STATUS.HOST}","{$NGINX.STUB_STATUS.PATH}","{$NGINX.STUB_STATUS.PORT}"]
```

模板不是写死 IP 和路径，而是用了三个宏变量 `{$...}`，这样不同主机可以有不同的值，模板本身保持通用。

### 4.5 配置主机宏

给每台关联了 Nginx 模板的主机添加三条宏：

| 宏 | 值 | 说明 |
|------|-----|------|
| `{$NGINX.STUB_STATUS.HOST}` | `127.0.0.1` | stub_status 监听的地址 |
| `{$NGINX.STUB_STATUS.PORT}` | `8080` | stub_status 监听的端口 |
| `{$NGINX.STUB_STATUS.PATH}` | `/nginx_status` | stub_status 的访问路径 |

**操作路径：** 数据采集 → 主机 → 点 web1 → 宏标签页 → 添加 → 更新

**为什么前两次只配了 HOST 和 PORT 时还不行？**

模板的 `web.page.get` key 有三个参数，缺了 PATH 时 Zabbix 用默认值去访问，返回 404。状态页虽然存在（我在浏览器 / curl 能访问到），但 Zabbix 访问的路径不对。

三条宏全部配好后，Get stub status page 返回 `HTTP/1.1 200 OK`，所有连接数据（Active、Reading、Writing、Waiting、Requests）全部正常采集。

### 4.6 各步骤的关联总结

```
status.conf（Nginx 暴露数据）
    ↓ 配合
UserParameter nginx.status[*]（Agent 知道怎么获取）
    ↓ 配合
Nginx 模板（Zabbix Server 知道请求什么 key）
    ↓ 配合
主机宏 HOST/PORT/PATH（告诉模板去哪读数据）
    ↓
最终：最新数据中 Nginx 连接/请求数据正常展示
```

四个环节缺一不可：缺 status.conf → curl 404；缺 UserParameter → Agent 不认识 nginx.status key；缺模板 → Server 不知道要采集什么；缺宏 → 模板访问路径错误。

---

## 第五部分：当前监控状态

### 已完成的监控

| 监控对象 | 模板 | 覆盖范围 |
|----------|------|----------|
| 全部 8 台 | Linux by Zabbix agent | CPU、内存、磁盘、网络、进程 |
| web1, web2, lb1, lb2 | Nginx by Zabbix agent | 连接数、请求数、响应状态 |

### 待完成的监控

| 待做 | 目标 |
|------|------|
| MySQL 服务监控 | 124 上的 MariaDB 查询量、慢查询、连接数 |
| Redis 服务监控 | 127 上的 Redis 命中率、内存、连接数 |
| PHP-FPM 监控 | 123, 126 上的 PHP 进程状态 |
| NFS 监控 | 125 上的共享存储可用性 |
| 触发器与告警 | 某个服务挂掉时自动通知 |
| 仪表盘 | 一屏展示所有关键指标 |

---

**文档创建时间：** 2026-06-30
