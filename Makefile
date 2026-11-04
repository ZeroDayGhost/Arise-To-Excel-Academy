SHELL := /bin/bash

# ghcr.io/zerodayghost/sch — must match the account CI pushes to.
GHCR_OWNER     := zerodayghost
PROJECT_NAME   := sch
IMAGE          := sch
DOMAIN         := sch.oleq.app
ENV_SUFFIX     := production
PROJECT_SUFFIX := prod

TAG ?= latest

export GHCR_OWNER PROJECT_NAME IMAGE TAG DOMAIN ENV_SUFFIX PROJECT_SUFFIX

COMPOSE_FILE := docker-compose.yml
COMPOSE_CMD  := docker compose -f $(COMPOSE_FILE) \
                -p $(PROJECT_NAME)_$(PROJECT_SUFFIX) \
                --env-file .env.$(ENV_SUFFIX)

## help: Show this help message
.PHONY: help
help:
	@echo "Usage: make [target] TAG=[version]"
	@echo ""
	@echo "Targets:"
	@sed -n 's/^##//p' $(MAKEFILE_LIST) | column -t -s ':' | sed -e 's/^/ /'

# Fails with a clear message when the server env file has not been created yet.
.env.$(ENV_SUFFIX):
	@echo "Missing .env.$(ENV_SUFFIX) in $$(pwd)."
	@echo "Copy .env.example to .env.$(ENV_SUFFIX) on the server and fill in the passwords."
	@exit 1

## pull: Pull app and database images
.PHONY: pull
pull: .env.$(ENV_SUFFIX)
	@echo "Pulling $(IMAGE):$(TAG)..."
	$(COMPOSE_CMD) pull

## up: Start the app and database
.PHONY: up
up: .env.$(ENV_SUFFIX)
	@echo "Starting production..."
	$(COMPOSE_CMD) up -d --remove-orphans

## deploy: Pull images and start the stack
.PHONY: deploy
deploy: pull up clean
	@echo "Successfully deployed $(IMAGE):$(TAG)"

## logs: Tail app and database logs
.PHONY: logs
logs:
	$(COMPOSE_CMD) logs -f --tail=200

## ps: Show container status
.PHONY: ps
ps:
	$(COMPOSE_CMD) ps

## down: Stop and remove containers
.PHONY: down
down:
	$(COMPOSE_CMD) down

## clean: Remove unused docker images
.PHONY: clean
clean:
	@echo "Cleaning up old images..."
	docker image prune -f
