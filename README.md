# LAMP 高可用平台

基于 Rocky Linux 9 的 LAMP 高可用 Web 架构，提供三套部署方案，覆盖从手动部署到自动化、从虚拟机到容器、从上线到监控的完整闭环。

## 项目结构

lamp-ha-platform/
├── ansible/          # Ansible 自动化部署
├── docker/           # Docker Compose 容器化部署
├── zabbix/           # Zabbix 全栈监控方案
├── docs/             # 架构图、效果截图
└── README.md

## 三套方案

| 方案 | 目录 | 适用场景 |
|------|------|----------|
| Ansible | ansible/ | 生产环境多节点批量部署 |
| Docker | docker/ | 开发测试、云服务器快速部署 |
| Zabbix | zabbix/ | 400+ 监控项全覆盖 |

## 架构概览

                浏览器
                  │
          Nginx LB
             /    \
      Web1        Web2
          \      /
        MySQL   Redis

## 技术栈

Rocky Linux 9 | Nginx 1.20 | PHP 8.0 | MariaDB 10.5 | Redis 6 | Keepalived | Ansible | Docker | Zabbix 6.4

## 快速开始

### Ansible
```bash
cd ansible && ansible-playbook site.yml
Docker
cd docker && docker compose up -d
Zabbix
参考 zabbix/ 目录下的部署文档
