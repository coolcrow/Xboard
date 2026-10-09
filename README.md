# AIBolt Panel (Xboard Fork)

基于 [Xboard](https://github.com/cedar2025/Xboard) 的面板分支，包含自研前端主题、节点 Agent 管理与远程升级。

## 一行命令安装

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash
```

脚本会问你：域名（必填，自动 HTTPS）+ 管理员邮箱 + SMTP（可选），其余全部自动：

| 自动完成 | 说明 |
|---|---|
| Docker 安装 | 未装则自动安装 |
| 镜像拉取 | 自动探测最优路径（直连/中国镜像） |
| HTTPS | 域名必填 → Caddy 自动 Let's Encrypt + 续期 |
| 管理员创建 | 邮箱+密码（自动生成或你指定） |
| 安全基线 | IP 限流、邮箱验证、HTTPS、登录锁定 |
| 定时任务 | 调度(每分钟) + 备份(每日) + 健康检查(每5分钟) |

也可全部通过参数指定（零交互）：

```bash
curl ... | sudo bash -s -- --domain panel.example.com --email admin@x.com --password Secret123
```

安装完成直接输出面板地址、管理员账号密码——浏览器打开即可使用。

---

## 手动部署

> 适用于无法使用一行命令安装的场景（如离线安装、定制镜像）。
> 详细步骤见 [QUICKSTART](./docs/QUICKSTART.md) | [QUICKSTART-EN](./docs/QUICKSTART-EN.md)。

## 套件组成

| 组件 | 仓库 | 说明 |
|---|---|---|
| 面板（本文档） | `coolcrow/Xboard` | Laravel + Octane，SQLite |
| 前端主题 | `coolcrow/xboard-web` | React SPA（用户门户 + 管理端 + 落地页 + 帮助文档） |
| 节点 Agent | `coolcrow/Xboard-Node` | Go，sing-box/xray 双内核，远程升级 |

## 部署方式

镜像与仓库均公开（`ghcr.io/coolcrow/xboard:bundle`），无需认证。

| 方式 | 适用 | 文档 |
|---|---|---|
| **一行命令安装** | 推荐（自动 Docker/Caddy/HTTPS/基线） | 上方 |
| Bundle 镜像 + compose | 无法使用安装器时 | [QUICKSTART](./docs/QUICKSTART.md) |
| 从源码构建 | 定制面板 | `Dockerfile.bundle` + `--secret id=webtoken` |
| 冷迁移 | 换服务器 | `docker save` + 数据目录 tar |

## 安装后配置

> 安装脚本已自动完成安全基线（限流/验证/锁定）。域名/SMTP/支付/节点的详细配置
> 见 [QUICKSTART](./docs/QUICKSTART.md) 第 2-4 步。

## 节点接入（面板装好后 2 分钟/台）

### 第一步：面板创建机器

登录管理端 → **机器管理** → **新建机器** → 复制生成的一键安装命令。

详细步骤（含中国大陆 --mirror 加速、接入架构配置）见 [QUICKSTART](./docs/QUICKSTART.md)。

## 完整部署文档

详细的架构说明、nginx 配置、镜像源搭建、故障排查等见：

**[docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md)**（中文）

客户快速开始：**[QUICKSTART](./docs/QUICKSTART.md)** / **[QUICKSTART-EN](./docs/QUICKSTART-EN.md)**

## 技术栈

- Backend: Laravel 11 + Octane (2 workers) + Horizon
- Frontend: React 18 + TypeScript + Tailwind CSS（自研主题）
- Agent: Go + sing-box / xray-core
- DB: SQLite (bind-mount) + Redis
- Deploy: Docker Compose + GHCR

## ⚠️ Disclaimer

Based on [Xboard](https://github.com/cedar2025/Xboard) (MIT License). For educational and commercial use within the terms of the original license.
