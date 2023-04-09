# glued-lib
Toolkit and dependency abstraction library for the glued project.

## Notifications

The notify class uses for most cases the symfony/notifier libraries under the hood, but provides
enough abstraction to enable easy integration of other code to communicate on platforms that are
unsupported by symfony.

The class requires a yaml configuration, where `dst` in every netowrk is an administrators'
destination (recipient) address. In each network (i.e. `email`), multiple (sending) channels can be
used. Currently, if the first channel fails to send, the class will try to continue sending by other
channels. This failover mechanism (intentionally) doesn't work between networks over privacy/security
concerns.

In case of admins, all destinations on all networks are used to really ensure admins get notified.
In case of users, users may change define one or more notification channels to be used always and
additional channels that can be used only by user choice.

### Configuration

To configure notifications, override defaults.yaml. An example here:

```yaml
notify:
    network:
        telegram:
            channels:
                - name: Telegram
                  dsn: 'telegram://123456789:yourbotsecret@default?channel='
            dst:
                - '111111111'
                - '222222222'
            config:
                src: '@MyTelegramBot'
        email:
            channels:
                - name: E-mail (smtp)
                  dsn: 'smtp://login:pass@mx1.example.com:587?encryption=starttls'
                - name: E-mail (smtp2)
                  dsn: 'smtp://login:pass@mx2.example.com:587?encryption=starttls'
            config:
                src: 'sender@example.com'
            dst:
                - 'recipient@gmail.com'
                - 'someotheradmin@outlook.com'
        sms:
            channels:
                - name: Twilio
                  dsn: 'twilio://SID:TOKEN@default?from=FROM'
                - name: O2
            config:
                src: '+1123456789'
```

### Usage

Send a notification to admins

```php
$this->notify->send(content: 'this is the message', subject: 'this is the optional header (i.e. mail subject', notify_admins: true);
```

Send a notification to specific users (all the channels below will be used)

```php
$users = [
    '45216b0c-32ca-4a48-8307-3466ee81f32e' => [
        'telegram'  => '222333222',
        'email' => 'user@example.com',
    ],
    '8e51819a-4595-4e5c-990c-f27cacb1a2dd' => [
        'telegram' => '2222777777',
    ],
];
$this->notify->to($users)->send('A quick notification only sent to $users');
```

Helper functions of the class will

```php
$res = $this->notify->getusers();
echo "<br><br>All users to be notified:";
print_r($res); 

$res = $this->notify->getadmins();
echo "<br><br>All admins to be notified:";
print_r($res); 

$res = $this->notify->status();
print_r($res);
echo "<br><br>Current configuration and send queue:";
```

### Supported network/channels are supported

Currently only the following networks/channels are supported:

- TELEGRAM
- EMAIL (SMTP)

**TELEGRAM**

Go to `https://web.telegram.org/k/#@BotFather` and type in

```
/newbot         # start setup
glued-dev       # bot name
glued_dev_bot   # bot username
```

Go to `https://web.telegram.org/k/#@RawDataBot` to get the chat_id of the recipient

You will end up with something like:

- Token: `1234567890:asecretstringapproximatelythisloong`
- Uri: `https://web.telegram.org/k/#@glued_dev_bot`
- Chat_id: `2244668800`

and a DSN string `TELEGRAM_DSN=telegram://1234567890:asecretstringapproximatelythisloong@default?channel=2244668800`

To test that the above works, use curl as follows:

```bash
TG_TOKEN=secret-token
TG_CHAT_ID=recipients-chat-id
curl -X POST -H "Content-Type:multipart/form-data" -F chat_id=$TG_CHAT_ID -F text="message" "https://api.telegram.org/bot$TG_TOKEN/sendMessage"
```

**EMAIL**

To test, use curl as follows:

```bash
SMTP_HOST='mail.example.com:587'
SMTP_USER='login:pass'
SMTP_FROM='sender@example.com'
SMTP_RCPT='recipient@example.com'

curl --ssl-reqd --url "smtp://$SMTP_HOST" --user "$SMTP_USER" --mail-from "$SMTP_FROM" --mail-rcpt "$SMTP_RCPT" --upload-file file.txt
cat file.txt
From: <test@noreply.com>
To: <pavel@vaizard.org>
Subject: Curl Test
```