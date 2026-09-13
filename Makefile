help:
	@echo "======================================================================"
	@echo " OPÇÕES DO MAKEFILE"
	@echo "======================================================================"
	@echo " exec NOME: Executa a comparação"
	@echo " Exemplo: make exec sobgestao"

exec:
	@echo "Executando comparação nos arquivos com o nome \"$(word 2,$(MAKECMDGOALS))\"..."
	@docker run --rm -it \
		--user "$(shell id -u):$(shell id -g)" \
		-v "$(CURDIR):/work" \
		-w /work \
		local/php-apache:8.4 \
		php index.php "$(word 2,$(MAKECMDGOALS))"

.DEFAULT:
	@:
