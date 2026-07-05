# Zabbix PHP-FPM 监控部署文档

## 概述

在 Web 节点（web1: 192.168.200.123, web2: 192.168.200.126）上启用 PHP-FPM 状态页，通过 `PHP-FPM by Zabbix agent` 内置模板实现进程状态、请求队列、慢请求等 26 个监控项的数据采集。

### 监控架构

```
PHP-FPM 内置状态页（pm.status_path = /phpfpm_status）
    ↓ Nginx 反向代理（127.0.0.1:8081 → Unix Socket）
curl http://127.0.0.1:8081/phpfpm_status?json
    ↑ UserParameter 执行
Zabbix Agent
    ↑ Zabbix Server 请求 key
PHP-FPM by Zabbix agent 模板（web.page.get + JSON 解析）
    ↑
Zabbix 前端展示
```

---

## 第一步：启用 PHP-FPM 状态页

在 `/etc/php-fpm.d/www.conf` 中启用状态路径：

```
pm.status_path = /phpfpm_status
```

| 配置 | 说明 |
|------|------|
| `pm.status_path` | PHP-FPM 内置功能，定义状态页的 URL 路径。默认是注释掉的（`;pm.status_path`） |
| `/phpfpm_status` | 自定义路径，和 Nginx 的 `stub_status` 类似，不暴露敏感信息 |

重启 PHP-FPM 后，还不能直接访问——状态页是 PHP-FPM 内部处理的，必须通过 Nginx 反向代理（fastcgi_pass）才能访问。

---

## 第二步：Nginx 反向代理状态页

创建 `/etc/nginx/conf.d/fpm_status.conf`：

```nginx
server {
    listen 127.0.0.1:8081;
    location /phpfpm_status {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

| 配置 | 说明 |
|------|------|
| `listen 127.0.0.1:8081` | 状态页用独立 8081 端口，只本机可访问，和 80/8080 互不冲突 |
| `fastcgi_pass unix:/run/php-fpm/www.sock` | 通过 Unix Socket 转发给 PHP-FPM，不走 TCP 端口 |
| `include fastcgi_params` | 加载 Nginx 内置的 FastCGI 标准参数（`$fastcgi_script_name` 等） |

**为什么需要 Nginx 转发？**

PHP-FPM 状态页不是 HTTP 服务，是 FastCGI 协议。浏览器和 curl 不能直接访问 Unix Socket 或 9000 端口——必须通过 Nginx 把标准 HTTP 请求转成 FastCGI 协议再传给 PHP-FPM。这和 WordPress 动态请求的处理流程一样，只是这里专门开了一个 8081 端口单独处理状态页。

**为什么用 8081 而不是复用 80 端口？**

80 端口已用于 WordPress，状态页和业务流量混在一起有安全隐患（暴露连接数、进程数等）且路径管理混乱。独立端口隔离清晰。

---

## 第三步：配置 UserParameter

创建 `/etc/zabbix/zabbix_agentd.d/phpfpm.conf`：

```
UserParameter=php-fpm.status[*],curl -s http://127.0.0.1:8081/phpfpm_status
```

模板使用 `web.page.get` key 请求状态页，并自动加 `?json` 后缀获取 JSON 格式。UserParameter 提供基础能力。

---

## 第四步：关联模板 + 配置宏

### 4.1 关联模板

Zabbix 前端 → 数据采集 → 主机 → web1/web2 → 模板 → 选择 `PHP-FPM by Zabbix agent` → 更新。

模板是 Zabbix 6.4 内置的，26 个监控项，无需手动导入。

### 4.2 配置主机宏（覆盖模板默认值）

模板默认宏值是针对标准部署的（`localhost:80/status`），我们的环境不同（`127.0.0.1:8081/phpfpm_status`），需在主机级别覆盖：

| 宏 | 模板默认值 | 我们的值 | 说明 |
|------|-----|-----|------|
| `{$PHP_FPM.HOST}` | `localhost` | `127.0.0.1` | 状态页 IP |
| `{$PHP_FPM.PORT}` | `80` | `8081` | 状态页端口 |
| `{$PHP_FPM.STATUS.PAGE}` | `status` | `/phpfpm_status` | 状态页路径 |

**主机宏 vs 模板宏的关系：**

模板定义默认宏值，保证开箱能用。主机上添加同名宏会覆盖默认值。其他宏（如 `{$PHP_FPM.PROCESS_NAME}`、`{$PHP_FPM.QUEUE.WARN.MAX}`）保持模板默认无需改动。

---

## 状态页返回数据

```json
{
  "pool": "www",
  "process manager": "dynamic",
  "start time": 1751366082,
  "start since": 71,
  "accepted conn": 1,
  "listen queue": 0,
  "max listen queue": 0,
  "listen queue len": 0,
  "idle processes": 4,
  "active processes": 1,
  "total processes": 5,
  "max active processes": 1,
  "max children reached": 0,
  "slow requests": 0
}
```

---

## 核心监控项（26 项）

### 进程状态
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Processes, active | 正在处理的请求数 | 长时间高说明 PHP 处理不过来 |
| Processes, idle | 空闲等待的进程数 | 降为 0 说明进程全忙 |
| Processes, total | 总进程数 | |
| Max children reached | 是否达到最大子进程数 | 1 表示进程池耗尽过 |

### 请求队列
| 监控项 | 说明 | 告警价值 |
|------|------|----------|
| Listen queue | 当前排队请求数 | 非 0 表示请求堆积 |
| Listen queue, max | 历史最大排队数 | |
| Queue usage | 队列使用率 % | > 80% 需告警 |
| Slow requests | 慢请求计数 | PHP 代码性能问题 |

### 连接与性能
| 监控项 | 说明 |
|------|------|
| Accepted connections per second | 每秒接受的新连接 |
| CPU utilization | PHP-FPM 进程的 CPU 占用 |
| Memory usage | RSS / VSZ / 百分比 |

---

## 与 Nginx 监控的对比

| 对比点 | Nginx | PHP-FPM |
|------|-------|---------|
| 数据来源 | `stub_status` 模块 | `pm.status_path` 状态页 |
| 暴露方式 | Nginx 自身提供（9090） | 需 Nginx fastcgi_pass 转发（8081） |
| 数据格式 | 纯文本（被模板正则解析） | JSON（`?json` 后缀） |
| 模板是否内置 | 否（需导入） | 是 |
| 端口规划 | 8080 | 8081 |

---

**文档创建时间：** 2026-07-01
