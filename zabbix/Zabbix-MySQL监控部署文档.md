# Zabbix MySQL 监控部署文档

## 概述

在 MySQL 服务器（192.168.200.124）上配置 Zabbix Agent，通过 `MySQL by Zabbix agent` 内置模板实现 48 个监控项的数据采集，覆盖连接数、查询量、InnoDB Buffer Pool、慢查询等关键指标。

### 监控架构

```
MariaDB 内置状态变量（SHOW GLOBAL STATUS）
    ↑ 查询（--xml 输出 XML 格式）
Zabbix 专用监控用户 zbx_monitor（最小权限）
    ↑ 连接
Agent UserParameter（执行 mysql --xml 查询）
    ↑ Zabbix Server 请求 key
MySQL by Zabbix agent 模板（XPath 解析 XML，正则提取数值）
    ↑
Zabbix 前端展示 / 触发器告警
```

---

## 第一步：创建 MySQL 监控用户

```bash
ansible mysql -m shell -a "mysql -e 'CREATE USER zbx_monitor@localhost IDENTIFIED BY \"zbx123\";'"
ansible mysql -m shell -a "mysql -e 'GRANT USAGE,PROCESS,REPLICATION CLIENT ON *.* TO zbx_monitor@localhost;'"
ansible mysql -m shell -a "mysql -e 'FLUSH PRIVILEGES;'"
ansible mysql -m shell -a "mysql -e 'SELECT User,Host FROM mysql.user WHERE User=\"zbx_monitor\";'"
```

| 命令 | 说明 |
|------|------|
| `CREATE USER 'zbx_monitor'@'localhost'` | 创建一个本地专用账户，只供 124 本机的 Agent 使用 |
| `GRANT USAGE` | 最小权限——允许登录，但不能操作任何数据库 |
| `GRANT PROCESS` | 允许执行 `SHOW PROCESSLIST`，查看当前运行中的查询 |
| `GRANT REPLICATION CLIENT` | 允许查看主从复制状态（`SHOW SLAVE STATUS`） |
| `FLUSH PRIVILEGES` | 刷新 MySQL 权限表，立即生效 |

**为什么是最小权限？**

Zabbix 只需要"看"数据，不需要"改"数据。`USAGE` + `PROCESS` + `REPLICATION CLIENT` 是 Zabbix 官方推荐的三权限组合——能拿到所有监控需要的状态变量，但无法查看业务库内容或修改任何数据。即使 Agent 被攻击者利用，损失也仅限于监控数据泄露。

---

## 第二步：配置 Agent UserParameter

在 124 上创建 `/etc/zabbix/zabbix_agentd.d/mysql.conf`：

```
UserParameter=mysql.ping[*],mysqladmin -u zbx_monitor -pzbx123 ping
UserParameter=mysql.get_status_variables[*],mysql -u zbx_monitor -pzbx123 --xml -e "SHOW GLOBAL STATUS" 2>/dev/null
UserParameter=mysql.version[*],mysql -u zbx_monitor -pzbx123 -V 2>/dev/null | sed "s/mysql  Ver [0-9.]* Distrib /Server version /"
```

| UserParameter | Zabbix key | 执行命令 | 返回数据 |
|------|------|------|------|
| `mysql.ping[*]` | `mysql.ping` | `mysqladmin ping` | `mysqld is alive` |
| `mysql.get_status_variables[*]` | `mysql.get_status_variables` | `mysql --xml -e "SHOW GLOBAL STATUS"` | XML 格式的全部状态变量 |
| `mysql.version[*]` | `mysql.version` | `mysql -V \| sed ...` | 版本号字符串 |

### 关键参数 `[*]` 的作用

Zabbix 模板调用的是带参数的 key，例如 `mysql.ping["127.0.0.1","3306"]`。`[*]` 是通配符——告诉 Agent "无论传什么参数，都执行同一个命令"。如果不写 `[*]` 只写 `mysql.ping`，模板带参数请求就会报 `Item does not allow parameters`。

### 为什么加 `--xml`？

**踩坑记录：** 模板内置的预处理规则使用 XPath 从 XML 中提取字段，例如：

```xpath
/resultset/row[field/text()='Aborted_clients']/field[@name='Value']/text()
```

这是解析 XML 结构的标准写法。但 MySQL 默认输出制表符分隔的纯文本（TSV），`<` 开头都没有，XPath 自然报错：

```
cannot extract XML value with xpath "..." : cannot parse xml value: Start tag expected, '<' not found
```

**解决：** 在 `mysql` 命令后加 `--xml`，输出变为：

```xml
<resultset statement="SHOW GLOBAL STATUS">
  <row>
    <field name="Variable_name">Aborted_clients</field>
    <field name="Value">0</field>
  </row>
</resultset>
```

XPath 就能正确定位到每个变量的值。

### MariaDB 版本号兼容

MariaDB 的 `mysql -V` 输出是 `mysql  Ver 15.1 Distrib 10.5.29-MariaDB...`，而模板期望 `Server version xxx`。用 `sed` 把格式转换成模板正则能匹配的格式。

---

## 第三步：关联模板 + 配置宏

### 3.1 关联模板

Zabbix 前端 → 数据采集 → 主机 → mysql1 → 模板 → 选择 `MySQL by Zabbix agent` → 更新。

该模板是 Zabbix 6.4 内置的，48 个监控项 + 11 个触发器 + 6 个图形，无需手动导入。

### 3.2 配置宏

数据采集 → 主机 → mysql1 → 宏标签页 → 添加：

| 宏 | 值 | 作用 |
|------|-----|------|
| `{$MYSQL.USER}` | `zbx_monitor` | 模板用此宏传给 Agent，告诉它用什么用户名连 MySQL |
| `{$MYSQL.PASSWORD}` | `zbx123` | 密码 |
| `{$MYSQL.HOST}` | `127.0.0.1` | MySQL 在哪个 IP（本机） |
| `{$MYSQL.PORT}` | `3306` | MySQL 端口 |

**四个宏缺一不可：** 前两个是鉴权，后两个是连接目标。模板里每个监控项的 key 就是 `mysql.xxx["{$MYSQL.HOST}","{$MYSQL.PORT}"]`，缺值则 key 解析异常。

---

## 第四步：验证

在 Zabbix Server（131）上使用 `zabbix_get` 模拟 Server 向 Agent 查询：

```bash
# 安装 zabbix-get 工具
dnf install -y zabbix-get

# 测试连接
zabbix_get -s 192.168.200.124 -k 'mysql.ping["127.0.0.1","3306"]'

# 测试状态变量
zabbix_get -s 192.168.200.124 -k 'mysql.get_status_variables["127.0.0.1","3306"]' | head -8

# 测试版本号
zabbix_get -s 192.168.200.124 -k 'mysql.version["127.0.0.1","3306"]'
```

`zabbix_get` 模拟的是 Zabbix Server → Agent 的完整查询链路，本地 `zabbix_agentd -t` 通过只能验证 Agent 自己能执行，不能验证 Server 能不能远程拿到。所以 `zabbix_get` 是更可靠的验证方式。

---

## 监控项清单（48 项）

### 连接类
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Connections per second | 每秒新建连接 | 突增可能是攻击 |
| Max used connections | 历史最大连接数 | 接近 `max_connections` 需扩容 |
| Threads connected | 当前连接数 | 实时状态 |
| Threads running | 正在执行查询的线程 | 高负载信号 |

### 查询类
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Queries per second | 每秒总查询数 | 看整体负载 |
| Questions per second | 每秒客户端发起查询数 | 不含存储过程内部查询 |
| Slow queries per second | 每秒慢查询 | SQL 性能问题 |
| Command Select/Insert/Update/Delete per second | 各类 SQL 操作分布 | 读写比例 |

### InnoDB 内存类
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Buffer pool efficiency | 命中率 | < 95% 说明内存不够，频繁读磁盘 |
| Buffer pool utilization | 使用率 | 是否接近上限 |
| Buffer pool pages free | 空闲页数 | 过少意味着即将用完 |
| Buffer pool read requests per second | 每秒逻辑读 | 包含命中缓存和物理读 |

### 线程与临时表
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Threads cached | 缓存的线程数 | |
| Created tmp tables on disk per second | 需要写磁盘的临时表 | 内存临时表不够用，SQL 需优化 |

---

## 遇到的问题及解决

| 问题 | 现象 | 原因 | 解决 |
|------|------|------|------|
| 预处理失败 | 所有监控项红色感叹号 | `mysql` 默认输出 TSV 文本，模板 XPath 解析 XML 报错 | 加 `--xml` 参数 |
| Item does not allow parameters | Status / Version 红色 | UserParameter 写 `mysql.ping`（无 `[*]`），模板传参数不匹配 | 改成 `mysql.ping[*]` |
| Version 正则不匹配 | Version 红色 | MariaDB 输出 `Distrib` 而非 `Server version` | `sed` 替换格式 |
| innodb_log_file_size 无数据 | 单个监控项红色 | MariaDB 不提供该变量，计算型监控项依赖缺失 | 非关键，忽略 |

---

**文档创建时间：** 2026-06-30
