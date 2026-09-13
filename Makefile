help:
	@echo "======================================================================"
	@echo " OPÇÕES DO MAKEFILE"
	@echo "======================================================================"
	@echo " exec: Executa a comparação em ambiente Docker"
	@echo ""

exec:
	@echo "Executando a comparação em ambiente Docker..."
	docker run --rm -it \
		--user "$(shell id -u):$(shell id -g)" \
		-v "$(CURDIR):/work" \
		-w /work \
		local/php-apache:8.4 \
		php index.php database.sql model.mwb
