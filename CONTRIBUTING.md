# Contribuindo

## Este repositório não aceita pull request

Ele é o espelho público de um pacote mantido internamente pela WiseData, e o
desenvolvimento acontece do lado de cá. Pull request aberto aqui é fechado sem
análise — não por desinteresse, mas porque não há como integrá-lo pelo caminho
que a manutenção usa.

**Abra uma issue.** É por onde tudo entra:

- **Defeito** — diga a versão do pacote, a do PHP, o que você chamou e o que
  aconteceu. Se houver exceção, cole a mensagem e o código em `$e->error`.
  **Nunca cole o token** (`wdm_...`): ele vale tanto quanto a senha da conta.
- **Sugestão** — descreva o problema que você está tentando resolver, não a
  solução que imaginou. Muitas vezes já existe caminho, e quando não existe o
  problema é o que orienta o desenho.
- **Trecho de código** é bem-vindo dentro da issue. É a forma de contribuir com
  a correção sem o pull request.

## Falha de segurança não é issue

Vai por e-mail privado — ver [SECURITY.md](SECURITY.md).

## Rodando os testes

```bash
composer install
vendor/bin/phpunit
```

Nenhum teste toca a rede: o `FakeTransport` devolve respostas programadas. Se
algum teste seu precisar de rede, ele está na camada errada.
