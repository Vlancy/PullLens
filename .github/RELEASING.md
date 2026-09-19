# Releasing PullLens

A release is a git tag. Pushing one builds the application image for `linux/amd64` and
`linux/arm64` and publishes it to [`vlancy/pulllens`](https://hub.docker.com/r/vlancy/pulllens).
Pushing to `dev` or `main` publishes nothing.

## Cutting one

```sh
# 1. VERSION must match the tag. The workflow refuses the release otherwise.
git checkout dev
echo 1.0.2 > VERSION
git commit -am "chore(release): 1.0.2"
git push origin dev

# 2. main is what operators track and what install.sh pulls.
git checkout main
git merge dev
git push origin main

# 3. The tag is what triggers the publish.
git tag -a v1.0.2 -m "PullLens 1.0.2"
git push origin v1.0.2
```

`VERSION` exists because `compose.yml`, the Nginx configuration and `install.sh` ship
in the operator's clone while the application ships in the image. They have to describe
the same release, so the tag is derived from the file rather than typed twice, and the
`guard` job fails the release if the two disagree.

## What gets published

A tag `v1.0.2` produces `1.0.2`, `1.0`, `1` and `latest`, all pointing at one manifest
list. A prerelease tag such as `v1.1.0-rc1` produces only `1.1.0-rc1` and deliberately
does not move `latest`, so a candidate can be published and tested without becoming
what a new operator installs.

Cut a candidate first when the change touches the Dockerfile or the build. The arm64
leg is the one that breaks.

## Required secrets

Repository secrets, under **Settings → Secrets and variables → Actions**. Environment
and organization secrets are not visible to this workflow.

| Secret | Scope needed | Used by |
| --- | --- | --- |
| `DOCKERHUB_USERNAME` | — | every job that talks to the registry |
| `DOCKERHUB_TOKEN` | **Read & Write** | pushing the image |
| `DOCKERHUB_DESC_TOKEN` | **Read, Write, Delete** | syncing the Hub overview |

Two tokens on purpose. Docker Hub's description endpoint answers `403` to a Read &
Write token that pushes images perfectly well, so it needs a wider one - and widening
the token that every build uses, to make a listing page update, is the wrong trade.

An account password would also satisfy the description endpoint. It is deliberately not
used: it is not scoped, not individually revocable, and fails under 2FA.

`DOCKERHUB_DESC_TOKEN` is optional. Without it the release succeeds and the overview is
left alone, with a notice in the run.

## The jobs

| Job | What it does |
| --- | --- |
| `guard` | Refuses the release if the secrets are missing or `VERSION` disagrees with the tag |
| `build` | Builds each architecture on its own native runner and pushes by digest |
| `merge` | Stitches the per-architecture digests into one manifest list and applies the tags |
| `description` | Syncs `.github/DOCKERHUB.md` to the Hub listing. Never fails the release |
| `smoke` | Runs the published image on both architectures: extensions, assets, docs, release stamp, and that no `.env`, `public/hot` or `.claude` leaked in |

Each architecture builds natively rather than under emulation. A QEMU arm64 build of
this image means emulating a PHP extension compile and a full Vite build - hours, with
a real chance of the Node process being killed.

## Afterwards

```sh
docker buildx imagetools inspect vlancy/pulllens:1.0.2   # both architectures present
docker scout quickview vlancy/pulllens:latest            # should match the base image
```

The image should contribute no vulnerabilities above `php:8.4-fpm-bookworm`. If it
does, something was added to the runtime stage that belongs in the build stage.
