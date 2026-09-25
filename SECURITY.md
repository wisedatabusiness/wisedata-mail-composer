# Política de segurança

## Versões que recebem correção

A linha mais recente publicada no Packagist. Correção de segurança sai como
versão nova de patch; não há backport para linhas anteriores.

## Como relatar uma falha

**Não abra issue pública** para falha de segurança — a issue fica visível para
todo mundo enquanto a correção não existe.

Escreva para **seguranca@wisedatamail.com** com o que você encontrou, como
reproduzir e o impacto que enxerga. Respondemos o recebimento em até dois dias
úteis e damos notícia do andamento a cada semana até fechar.

Pedimos que você não divulgue a falha até a correção estar publicada. Damos
crédito no anúncio da versão a quem quiser.

## O que é falha deste pacote

Este repositório é só o **cliente PHP**. Falha no serviço (`api.wisedatamail.com`)
vai para o mesmo endereço, mas não é corrigida aqui.

Casos que interessam aqui: o token da conta vazando por log, mensagem de erro ou
requisição fora do destino; corpo de requisição montado de forma que permita
injetar cabeçalho de e-mail; verificação de TLS que possa ser contornada;
execução de código a partir de uma resposta da API.

## O que o pacote garante

- O endereço da API precisa ser `https` — exceto endereço local, onde não há
  caminho para escutar. Com qualquer outra coisa o cliente recusa a construção,
  em vez de mandar o token em claro.
- A verificação de certificado é declarada pelo pacote, não herdada do `php.ini`
  do servidor.
- Redirecionamento não é seguido: um `301` levaria o `Authorization` junto para
  onde quer que ele aponte.
- Cabeçalho passado por quem chama não sobrescreve `Authorization`.

## O token

Ele vale tanto quanto a senha da conta: quem o tem lê e escreve a base de
contatos inteira e dispara e-mail em nome dela. Guarde no `.env`, nunca no
repositório, e revogue na tela de Integrações ao menor sinal de exposição.
