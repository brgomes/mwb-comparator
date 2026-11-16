PHP_IMAGE ?= local/php-apache:8.4
CONFIG ?= $(word 2,$(MAKECMDGOALS))
CONFIG_FILE ?= $(CURDIR)/config.php
MWB_DIR ?=
CONFIG_DIR = $(abspath $(dir $(CONFIG_FILE)))

.PHONY: help exec test

help:
	@echo 'make exec projeto_a               Usa config.php["projeto_a"]'
	@echo 'make exec projeto_b               Usa config.php["projeto_b"]'
	@echo 'A rede Docker e a pasta do modelo são lidas de config.php.'
	@echo 'make exec CONFIG=projeto_a CONFIG_FILE=/caminho/config.php'
	@echo 'make test                         Executa testes sem banco externo'

exec:
	@$(if $(strip $(CONFIG)),:,$(error Uso: make exec NOME. Exemplo: make exec projeto_a))
	@docker_options="$$(docker run --rm \
			--user "$(shell id -u):$(shell id -g)" \
			--network none \
			--mount "type=bind,source=$(CURDIR),target=/work,readonly" \
			--mount "type=bind,source=$(CONFIG_DIR),target=$(CONFIG_DIR),readonly" \
			-w /work --entrypoint php $(PHP_IMAGE) src/docker-options.php \
			"$(CONFIG)" "$(abspath $(CONFIG_FILE))")" || exit $$?; \
	docker_network="$$(printf '%s\n' "$$docker_options" | sed -n '1p')"; \
	model_dir="$(MWB_DIR)"; \
	if [ -z "$$model_dir" ]; then \
		model_dir="$$(printf '%s\n' "$$docker_options" | sed -n '2p')"; \
	fi; \
	model_dir="$$(realpath -- "$$model_dir")" || exit $$?; \
	set --; \
	if [ "$$model_dir" != "$(CONFIG_DIR)" ]; then \
		set -- --mount "type=bind,source=$$model_dir,target=$$model_dir,readonly"; \
	fi; \
	docker run --rm \
		--user "$(shell id -u):$(shell id -g)" \
		--network "$$docker_network" \
		--mount "type=bind,source=$(CURDIR),target=/work" \
		"$$@" \
		--mount "type=bind,source=$(CONFIG_DIR),target=$(CONFIG_DIR)" \
		-w /work \
		--entrypoint php $(PHP_IMAGE) index.php "$(CONFIG)" "$(abspath $(CONFIG_FILE))"

test:
	@docker run --rm --user "$(shell id -u):$(shell id -g)" \
		--mount "type=bind,source=$(CURDIR),target=/work,readonly" \
		-w /work --entrypoint php $(PHP_IMAGE) tests/run.php

.DEFAULT:
	@:
