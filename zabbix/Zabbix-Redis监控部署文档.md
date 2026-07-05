# Zabbix Redis 监控部署文档

## 概述

在 Redis 服务器（192.168.200.127）上配置 Zabbix Agent，通过 `Redis by Zabbix agent 2` 内置模板实现 114 个监控项的数据采集，覆盖内存、连接数、命中率、持久化、CPU 等关键指标。

### 监控架构

```
redis-cli INFO 输出（key:value 纯文本）
    ↓ Python 脚本转换为 JSON
Zabbix Agent UserParameter（执行转换脚本）
    ↑ Zabbix Server 请求 key
Redis by Zabbix agent 2 模板（JSONPath 解析各字段）
    ↑
Zabbix 前端展示 / 触发器告警
```

---

## 第一步：配置 UserParameter

在 127 上创建 `/etc/zabbix/zabbix_agentd.d/redis.conf`：

```
UserParameter=redis.ping[*],redis-cli -h 127.0.0.1 -p 6379 PING
UserParameter=redis.info[*],/usr/local/bin/redis_info_json.sh
UserParameter=redis.slowlog[*],redis-cli -h 127.0.0.1 -p 6379 SLOWLOG GET 10
UserParameter=redis.config[*],redis-cli -h 127.0.0.1 -p 6379 CONFIG GET '*'
```

| UserParameter | Zabbix key | 命令 | 说明 |
|------|------|------|------|
| `redis.ping[*]` | `redis.ping` | `redis-cli PING` | 存活检测，返回 `PONG` |
| `redis.info[*]` | `redis.info` | 执行 JSON 转换脚本 | 返回全部状态数据的 JSON |
| `redis.slowlog[*]` | `redis.slowlog` | `redis-cli SLOWLOG GET 10` | 最近 10 条慢查询 |
| `redis.config[*]` | `redis.config` | `redis-cli CONFIG GET '*'` | Redis 配置参数 |

---

## 第二步：Redis INFO 文本转 JSON 脚本

**踩坑：** `Redis by Zabbix agent 2` 模板用 JSONPath 解析数据，但 `redis-cli INFO` 默认输出纯文本 `key:value` 格式。直接返回会被模板拒绝：

```
cannot extract value from json by path "$.Cluster.cluster_enabled":
invalid object format, expected opening character '{' or '['
```

**解决：** 创建脚本 `/usr/local/bin/redis_info_json.sh`，将 Redis INFO 文本按 `# Section` 分组转为 JSON：

```bash
#!/bin/bash
redis-cli -h 127.0.0.1 -p 6379 INFO | python3 -c "
import sys, json
d, section = {}, ''
for line in sys.stdin:
    line = line.strip()
    if line.startswith('#'):
        section = line[2:].strip()
        d[section] = {}
    elif ':' in line and section:
        k, v = line.split(':', 1)
        d[section][k] = v
print(json.dumps(d))
"
```

**转换效果：**

原始输出：
```
# Server
redis_version:6.2.22
# Clients
connected_clients:1
blocked_clients:0
```

转换后 JSON：
```json
{"Server": {"redis_version": "6.2.22"}, "Clients": {"connected_clients": "1", "blocked_clients": "0"}}
```

模板就能用 `$.Clients.connected_clients` 这样的 JSONPath 提取值了。

---

## 第三步：关联模板 + 配置宏

### 3.1 关联模板

Zabbix 前端 → 数据采集 → 主机 → redis1 → 模板 → 选择 `Redis by Zabbix agent 2` → 更新。

模板是 Zabbix 6.4 内置的，114 个监控项无需手动导入。

### 3.2 配置宏

redis1 → 宏标签页 → 添加：

| 宏 | 值 | 说明 |
|------|-----|------|
| `{$REDIS.CONN.URI}` | `tcp://127.0.0.1:6379` | Redis 连接 URI，格式 `协议://主机:端口` |

Redis 没有设密码，无需认证参数。

---

## 第四步：验证

在 Zabbix Server（131）上：

```bash
# 测试存活
zabbix_get -s 192.168.200.127 -k 'redis.ping["tcp://127.0.0.1:6379"]'

# 测试状态数据（应返回 JSON）
zabbix_get -s 192.168.200.127 -k 'redis.info["tcp://127.0.0.1:6379"]' | python3 -m json.tool | head -20
```

---

## 核心监控项（114 项中重点）

### 连接类
| 监控项 | JSONPath | 告警价值 |
|------|------|----------|
| Connected clients | `$.Clients.connected_clients` | 当前客户端连接数 |
| Blocked clients | `$.Clients.blocked_clients` | 被阻塞客户端，非 0 需排查 |
| Rejected connections | `$.Stats.rejected_connections` | 连接超限被拒次数 |

### 内存类
| 监控项 | JSONPath | 告警价值 |
|------|------|----------|
| Used memory | `$.Memory.used_memory_human` | 当前内存使用量 |
| Memory fragmentation ratio | `$.Memory.mem_fragmentation_ratio` | >1.5 说明内存碎片严重 |
| Maxmemory | `$.Memory.maxmemory` | 最大内存限制，0 表示无限制 |

### 命中率
| 监控项 | JSONPath | 告警价值 |
|------|------|----------|
| Keyspace hits | `$.Stats.keyspace_hits` | 命中次数 |
| Keyspace misses | `$.Stats.keyspace_misses` | 未命中次数 |
| 命中率 | hits/(hits+misses) | < 90% 需要关注 |

### 持久化
| 监控项 | 说明 |
|------|------|
| RDB last save time | 上次 RDB 保存时间 |
| RDB last bgsave status | 上次 RDB 备份状态（ok/err） |
| AOF enabled | AOF 是否开启 |

### 慢查询
| 监控项 | 说明 |
|------|------|
| Slowlog | 最近 10 条慢查询命令和耗时 |

---

## 与 MySQL 监控的对比

| 对比点 | MySQL | Redis |
|------|-------|-------|
| 数据来源 | `SHOW GLOBAL STATUS` | `INFO` |
| 输出格式 | 需 `--xml` 转 XML | 需 Python 脚本转 JSON |
| 模板解析 | XPath | JSONPath |
| 是否需要建用户 | 是（zbx_monitor + 密码） | 否（Redis 无认证） |
| 模板是否内置 | 是 | 是 |

---

## 遇到的问题及解决

| 问题 | 现象 | 原因 | 解决 |
|------|------|------|------|
| INFO 格式不兼容 | 大量监控项红色感叹号 | 模板用 JSONPath，但 `redis-cli INFO` 输出纯文本 | Python 脚本按 `# Section` 分组转 JSON |
| Get config 不支持 | 单个红色感叹号 | 补充 UserParameter | 加 `redis.config[*]` |

---

**文档创建时间：** 2026-07-01
