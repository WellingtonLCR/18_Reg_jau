# Loja Regional Jaú — CMD XLVII

## Banco de dados

1. No phpMyAdmin, selecione o banco `18_mc_reg_jau`.
2. Abra **Importar** e envie o arquivo `database.sql`.
3. O projeto usa `root` sem senha como padrão local do XAMPP. Caso necessário, altere `config.php` ou defina as variáveis `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS`.

## Execução local

Coloque a pasta no diretório público do Apache (por exemplo, `htdocs`) e acesse pelo navegador. A API está em `api.php` e oferece catálogo, cadastro de cliente e criação de pedidos com transação e baixa de estoque.

## Publicação

O login depende de `api.php`, PHP 8+ e MySQL. Hospedagens estáticas não executam PHP: nelas, a requisição de login recebe uma página HTML/erro em vez de JSON. Publique todos os arquivos deste projeto em um servidor com PHP e MySQL, importe `database.sql` e ajuste as credenciais no `config.php` (ou pelas variáveis de ambiente `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS`).
