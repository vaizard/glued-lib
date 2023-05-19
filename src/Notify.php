<?php
declare(strict_types=1);
namespace Glued\Lib;
use Http\Client\Exception;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn as MailerDsn;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Address;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notifier;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Bridge\Symfony\Mailer\NotifierSymfonyMailerTransport;
use Symfony\Component\Notifier\Bridge\Symfony\Mailer\SymfonyMailerTransport;
use Symfony\Component\Notifier\Transport\Dsn as NotifierDsn;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransportFactory;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\Channel\ChatChannel;
use Symfony\Component\Notifier\Channel\EmailChannel;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;

class Notify
{

    protected $users;
    protected $settings;
    protected $logger;
    protected $admins;

    public function __construct($settings, $logger)
    {
        $this->settings = $settings['notify'];
        $this->logger = $logger;
        $this->users = [];
        // on class invocation, get the admin destinations from $this->settings
        $this->admins = $this->destinations();
        // remove admin destinations (the method actually clears all the destinations, but at class invocation
        // only admin destinations are defined). To later send notifications to admins, $this->settings needs to be
        // merged with $this->admins
        $this->clear_to();
    }


    public function destinations(): array {
        foreach ($this->settings['network'] as $k => $v) {
            $ret[$k] = $v['dst'] ?? [];
        }
        return $ret ?? [];
    }

    public function getadmins(): array {
        return $this->admins;
    }

    public function clear_to(): self {
        foreach ($this->settings['network'] as $k => &$v) {
            $v['dst'] = [];
        }
        return $this;
    }

    public function getusers(): array {
        return $this->users;
    }

    public function to(array $r): self {
        $this->users = array_merge_recursive($this->users, $r);
        foreach ($this->settings['network'] as $channel => &$config) {
            $recipients = $config['dst'] ?? [];
            $newRecipients = array_filter(array_column($r, $channel));
            $recipients = array_unique(array_merge($recipients, $newRecipients));
            $config['dst'] = $recipients;
        }
        return $this;
    }

    public function status(): array {
        return $this->settings;
    }

    public function send(string $content, string $subject = 'Glued notification', bool $notify_admins = false)
    {
        if ($notify_admins === true) {
            $r = $this->getadmins();
            foreach ($this->settings['network'] as $channel => &$config) {
                $config['dst'] = array_unique(array_merge($config['dst'] ?? [], $r[$channel]));
            }
        }

        $chat = new ChatMessage($content);
        $push = new PushMessage($subject, $content);
        $mail = (new Email())
            ->from($this->settings['network']['email']['config']['src'])
            ->subject($subject)
            ->text($content);

        foreach ($this->settings['network'] as $type => $network) {
            // ===========================================================
            // Channels requiring a separate transport for every recipient
            // ===========================================================
            if ($type == 'telegram') {
                $success = false; // Flag to help iterate over all provided channel DSNs
                foreach ($this->settings['network'][$type]['channels'] as $channel) {
                    try {
                        foreach ($network['dst'] as $key => $dst) {
                            $dsn = new NotifierDsn($channel['dsn'] . $dst);
                            $transport = (new TelegramTransportFactory)->create($dsn);
                            $transport->send($chat);
                            unset($this->settings['network'][$type]['dst'][$key]); // On successful send remove destination (recipient)
                        }
                        $success = true;
                        break; // Exit the loop if the email was sent successfully with current DSN
                    } catch (\Exception $e) {
                        // TODO Handle the exception better (e.g. log it)
                        continue; // Try the next DSN in the list
                    }
                }
                if (!$success) {
                    $this->logger->error("lib.notify failed to send some " . $type . " notifications.");
                }
            }

            // ===========================================================
            // Channels ok with a single transport for multiple recipients
            // ===========================================================
            elseif ($type == 'email') {
                $success = false; // Flag to help iterate over all provided channel DSNs
                foreach ($this->settings['network'][$type]['channels'] as $channel) {
                    $dsn = MailerDsn::fromString($channel['dsn']);
                    try {
                        $transport = (new EsmtpTransportFactory)->create($dsn);
                        foreach ($network['dst'] as $key => $dst) {
                            $envelope = new Envelope(new Address($this->settings['network'][$type]['config']['src']), [new Address($dst)]);
                            $transport->send(new RawMessage($mail->to($dst)->toString()), $envelope);
                            unset($this->settings['network'][$type]['dst'][$key]); // On successful send remove destination (recipient)
                        }
                        $success = true;
                        break; // Exit the loop if the email was sent successfully with current DSN
                    } catch (\Exception $e) {
                        // TODO Handle the exception better (e.g. log it)
                        continue; // Try the next DSN in the list
                    }
                }
                if (!$success) {
                    $this->logger->error("lib.notify failed to send some " . $type . " notifications.");
                }
            }

            // ===========================================================
            // Unhandled channels
            // ===========================================================
            else {
                $this->logger->error("lib.notify configured to send unsupported type of notifications (" . $type . ").");
            }
        }
    }

    // TODO: add a property to store recipients to which the notification could not be sent successfully
    //       basically just extract the recipients from $this->config after send()

}