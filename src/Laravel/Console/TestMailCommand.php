<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\WiseDataMailException;
use WiseData\Mail\Mailer\WiseDataMailTransport;
use WiseData\Mail\Mailer\WiseDataMailTransportException;

/**
 * `php artisan wisedata-mail:test voce@exemplo.com`
 *
 * Síncrono de propósito, e pelo mailer — não pelo `Client` direto: é o mesmo
 * caminho de um `Mailable` (token, espaço, remetente), e quem roda quer ver o
 * aceite ou o erro da API na hora.
 *
 * Assunto `PING`, corpo `PONG`: é entendido em qualquer idioma, e o pacote não
 * precisa saber em que língua está a aplicação que o usa.
 *
 * O id da mensagem não é impresso. Ele não ajuda quem roda o comando — o
 * resultado é o e-mail chegar —, e o terminal do servidor vai parar em log de
 * deploy e em print de chamado. Para achar a mensagem, o painel busca pelo
 * destinatário.
 */
final class TestMailCommand extends Command
{
    protected $signature = 'wisedata-mail:test
        {email : Destinatário do e-mail de teste}
        {--mailer=wisedatamail : Mailer do config/mail.php que usa o transport wisedatamail}';

    protected $description = 'Envia um PING pelo WiseData Mail (o corpo é PONG) e mostra o aceite ou o código do erro';

    public function handle(): int
    {
        $email = $this->argument('email');
        $email = is_string($email) ? trim($email) : '';
        $nome = $this->option('mailer');
        $nome = is_string($nome) && $nome !== '' ? $nome : 'wisedatamail';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("E-mail inválido: {$email}");

            return self::FAILURE;
        }

        try {
            $mailer = Mail::mailer($nome);

            if (! $mailer instanceof Mailer || ! $mailer->getSymfonyTransport() instanceof WiseDataMailTransport) {
                $this->error("O mailer [{$nome}] não usa o transport wisedatamail.");

                return self::FAILURE;
            }

            $this->line("Remetente: {$this->remetente($nome)}");
            $this->line("Enviando para {$email} pelo mailer [{$nome}]...");

            // A API exige `html` quando não há `template`: o `raw()` mandava só texto e voltava 422.
            $mailer->html(
                '<p>PONG</p>',
                static fn (Message $mensagem) => $mensagem->to($email)->subject('PING')->text('PONG'),
            );
        } catch (WiseDataMailTransportException $e) {
            $this->error("A API recusou o envio ({$e->error}): {$e->getMessage()}");
            $this->detalharValidacao($e);

            return self::FAILURE;
        } catch (WiseDataMailException|TransportExceptionInterface|InvalidArgumentException $e) {
            /* Token vazio, URL sem https, mailer inexistente ou rede: a API nem respondeu. */
            $this->error("O envio não chegou à API: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Aceito pelo WiseData Mail.');

        return self::SUCCESS;
    }

    private function remetente(string $mailer): string
    {
        $daConta = config("mail.mailers.{$mailer}.use_account_sender", config('wisedata-mail.mail.use_account_sender'));

        if (filter_var($daConta, FILTER_VALIDATE_BOOLEAN)) {
            return 'o remetente padrão da conta no WiseData Mail';
        }

        $endereco = config("mail.mailers.{$mailer}.from.address", config('mail.from.address'));

        return is_string($endereco) && $endereco !== ''
            ? "{$endereco} (precisa ser um remetente verificado no WiseData Mail)"
            : 'o remetente padrão da conta no WiseData Mail';
    }

    private function detalharValidacao(WiseDataMailTransportException $e): void
    {
        $api = $e->getPrevious();

        if (! $api instanceof ApiException || ! is_array($api->extra['errors'] ?? null)) {
            return;
        }

        foreach ($api->extra['errors'] as $campo => $mensagens) {
            foreach ((array) $mensagens as $mensagem) {
                $this->line("  {$campo}: ".(is_string($mensagem) ? $mensagem : json_encode($mensagem)));
            }
        }
    }
}
