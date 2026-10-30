# フォームの完了処理でメールを送る

フォームの完了処理(`onComplete`)から`wp_mail()`でメールを送る。送信のクラスは`app/Mail/`に置き、フォームの定義からは呼ぶだけにする。
例はテーマのもの。コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

- 例は、rules/form.mdのお問い合わせフォーム(`App\Form\ContactForm`)の管理者への通知と自動返信である。項目のラベルと選択肢の表示は`ContactForm`から読む。
- 例のテキストドメイン`my-theme`は、テーマのstyle.css(プラグインはプラグインヘッダ)の`Text Domain`に置き換える。メールの文言もソースを英語にし、日本語は翻訳ファイルで用意する。

## 手順

1. `app/Mail/MailHeader.php`(ヘッダに入れる値の検査)と`app/Mail/MailSendException.php`(送信の失敗)を作る。
2. `app/Mail/ContactMailer.php`に、宛先・件名・本文の組み立てと送信を書く。
3. フォームの`onComplete()`から`(new ContactMailer())->send($values)`を呼ぶ(rules/form.mdの`ContactForm`)。
4. `composer qa`を通し、実際に送って届くことを確かめる。

## 完全な実装例

### ヘッダに入れる値の検査

`app/Mail/MailHeader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * 利用者の入力をメールのヘッダ(宛先・件名・Reply-To)に入れるときの検査と整形。
 * ヘッダの値に改行が残ると、ヘッダの区切りとして解釈され、Bcc等のヘッダを差し込まれる(ヘッダインジェクション)。
 */
final class MailHeader
{
    private function __construct() {}

    /** 件名等の文字列から、改行とそのほかの制御文字を取り除く(空白1つに置き換える) */
    public static function text(string $value): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        if ($text === null) {
            throw new \UnexpectedValueException('a mail header value must be valid UTF-8');
        }

        return trim($text);
    }

    /**
     * 宛先・Reply-Toにするメールアドレスを確かめて返す。改行・空白・表示名を含む値は通らない。
     * フォームのEmailルールと同じ検査のため、検証済みの値が通らなければ呼び出し側の誤りとして例外にする。
     */
    public static function address(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \UnexpectedValueException('a mail header address must be a valid email address');
        }

        return $value;
    }
}
```

### 送信の失敗

`app/Mail/MailSendException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

/** wp_mail()が送信に失敗した。メッセージに宛先・件名・本文を入れない */
final class MailSendException extends \RuntimeException {}
```

### 送信

`app/Mail/ContactMailer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Form\ContactForm;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Log\Log;

/** お問い合わせフォームの完了処理。管理者へ通知し、利用者へ自動返信する */
final class ContactMailer
{
    /**
     * @param array<string, mixed> $values onCompleteが受け取った値(検証済み)
     *
     * @throws MailSendException どちらかのメールを送れなかった場合と、添付ファイルを読めない場合
     */
    public function send(array $values): void
    {
        $email = MailHeader::address(self::string($values, 'email'));
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subjectLabel = self::choiceLabel(ContactForm::subjects(), self::string($values, 'subject'));

        // 1. 管理者への通知。返信の宛先はReply-Toに入れ、Fromは変えない
        $this->deliver(
            'admin_notification',
            MailHeader::address(get_bloginfo('admin_email')),
            /* translators: 1: site name, 2: inquiry type, 3: sender name */
            MailHeader::text(sprintf(__('[%1$s] %2$s from %3$s', 'my-theme'), $siteName, $subjectLabel, self::string($values, 'full_name'))),
            $this->adminBody($values),
            ['Reply-To: ' . $email],
            $this->attachments($values),
        );

        // 2. 利用者への自動返信。取りこぼせない管理者への通知を先に送る(自動返信だけが失敗した場合、再送で管理者への通知が重複する)
        $this->deliver(
            'auto_reply',
            $email,
            /* translators: %s: site name */
            MailHeader::text(sprintf(__('[%s] Thank you for your inquiry', 'my-theme'), $siteName)),
            $this->autoReplyBody($siteName, $subjectLabel),
            [],
            [],
        );
    }

    /**
     * @param list<string> $headers 1要素1ヘッダ。利用者の入力はMailHeaderを通した値だけを入れる
     * @param array<string, string> $attachments 添付のファイル名 => パス
     *
     * @throws MailSendException
     */
    private function deliver(string $mail, string $to, string $subject, string $body, array $headers, array $attachments): void
    {
        if (wp_mail($to, $subject, $body, $headers, $attachments)) {
            return;
        }

        // 宛先・件名・本文と、wp_mail_failedのエラーの文言(アドレスを含みうる)は記録しない
        Log::scope('mail')->error('mail {mail} of form {form} could not be sent', ['form' => 'contact', 'mail' => $mail]);

        throw new MailSendException("failed to send mail: {$mail}");
    }

    /** @param array<string, mixed> $values */
    private function adminBody(array $values): string
    {
        $lines = [];

        foreach (['full_name', 'kana', 'company', 'email', 'phone', 'address'] as $name) {
            $lines[] = ContactForm::label($name) . ': ' . self::string($values, $name);
        }

        $lines[] = ContactForm::label('subject') . ': ' . self::choiceLabel(ContactForm::subjects(), self::string($values, 'subject'));
        $lines[] = ContactForm::label('contact_method') . ': ' . self::choiceLabel(ContactForm::contactMethods(), self::string($values, 'contact_method'));

        $products = [];

        foreach (self::strings($values, 'products') as $product) {
            $products[] = self::choiceLabel(ContactForm::products(), $product);
        }

        $lines[] = ContactForm::label('products') . ': ' . implode(', ', $products);

        $file = self::file($values, 'attachment');
        $lines[] = ContactForm::label('attachment') . ': ' . ($file instanceof StoredFile ? $file->clientName : '-');
        $lines[] = '';
        $lines[] = ContactForm::label('message') . ':';
        $lines[] = self::string($values, 'message');

        return implode("\n", $lines);
    }

    /** 自動返信の本文。利用者が自由に書いた文言(氏名・お問い合わせ内容)は入れない */
    private function autoReplyBody(string $siteName, string $subjectLabel): string
    {
        return implode("\n", [
            /* translators: %s: site name */
            sprintf(__('Thank you for contacting %s.', 'my-theme'), $siteName),
            '',
            /* translators: %s: inquiry type */
            sprintf(__('We have received your inquiry (%s) and will reply as soon as possible.', 'my-theme'), $subjectLabel),
            __('This email was sent automatically. If you did not send this inquiry, please ignore this email.', 'my-theme'),
        ]);
    }

    /**
     * 添付ファイルはメールに付けるだけで消費しない。一時ファイルは完了の後にFWが消す。
     *
     * @param array<string, mixed> $values
     *
     * @throws MailSendException 添付ファイルが無いか、読めない場合
     *
     * @return array<string, string> ファイル名 => パス
     */
    private function attachments(array $values): array
    {
        $file = self::file($values, 'attachment');

        if (!$file instanceof StoredFile) {
            return [];
        }

        // wp_mail()は読めない添付を黙って外し、本文だけのメールを成功として返す。exists()はファイルの有無だけを見るため、
        // 読めるかどうかも送る前に確かめ、読めなければ送信の失敗にする(onCompleteの例外になり、利用者が送り直せる)
        if (!$file->exists() || !is_readable($file->path)) {
            // パスとファイル名は記録しない
            Log::scope('mail')->error('the attachment of form {form} is not readable', ['form' => 'contact']);

            throw new MailSendException('the attachment of the contact form is not readable');
        }

        // clientNameはsanitize_file_name()済み(改行・制御文字を含まない)
        return [$file->clientName => $file->path];
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $name): string
    {
        $value = $values[$name] ?? null;

        if (!\is_string($value)) {
            throw new \LogicException("contact form value '{$name}' must be a string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values, string $name): array
    {
        $value = $values[$name] ?? null;

        if (!\is_array($value)) {
            throw new \LogicException("contact form value '{$name}' must be a list");
        }

        $strings = [];

        foreach ($value as $element) {
            if (!\is_string($element)) {
                throw new \LogicException("contact form value '{$name}' must be a list of strings");
            }

            $strings[] = $element;
        }

        return $strings;
    }

    /** @param array<string, mixed> $values */
    private static function file(array $values, string $name): ?StoredFile
    {
        $value = $values[$name] ?? null;

        if ($value === '') {
            return null;
        }

        if (!$value instanceof StoredFile) {
            throw new \LogicException("contact form value '{$name}' must be a stored file or ''");
        }

        return $value;
    }

    /** @param array<array-key, string> $choices */
    private static function choiceLabel(array $choices, string $value): string
    {
        if (!\array_key_exists($value, $choices)) {
            throw new \LogicException("unknown choice of the contact form: {$value}");
        }

        return $choices[$value];
    }
}
```

## ヘッダインジェクション対策

宛先・件名・ヘッダに利用者の入力を入れる場合は、次のとおりにする。

- 件名に入れる値は`MailHeader::text()`で改行と制御文字を取り除く。改行が残ると、件名の後に`Bcc:`等のヘッダを差し込まれる。
- 宛先とReply-Toのメールアドレスは、フォームの`Email`ルールで検証し、送る前に`MailHeader::address()`でもう一度確かめる。`FILTER_VALIDATE_EMAIL`は改行・空白を含む値を通さない。
- 利用者の入力をアドレスの表示名(`"山田" <taro@example.com>`の`山田`)に入れない。表示名の引用符や`<`・`,`で、宛先を増やされうる。
- ヘッダは配列で渡し、1要素に1ヘッダを入れる。利用者の入力を含むヘッダの文字列を連結して作らない。
- 利用者に宛先を選ばせる場合(部署の選択等)は、選択肢の値をアドレスにしない。選択肢は`'sales'`のような識別子にし、サーバー側の対応表でアドレスを引く。
- 本文はテキストのメール(`wp_mail()`の既定)にする。HTMLのメールにする場合は、利用者の入力をすべて`esc_html()`する。

## 自動返信

- 宛先は利用者が入力したアドレスである。他人のアドレスを入れて送信されると、そのアドレスにサイトからメールが届く。自動返信の本文に利用者が自由に書いた文言(氏名・お問い合わせ内容)を入れない。入れると、任意の文面を第三者へ送る踏み台に使われる。
- 同じ理由で、自動返信を送るフォームにはスパム対策(rules/form.mdの「スパム対策(honeypot・Turnstile)を差し込む」)を入れる。
- 送信元(From)は変えない。利用者のアドレスをFromにすると、送信ドメイン認証(SPF・DMARC)に失敗して届かない。利用者への返信は管理者への通知のReply-Toで行う。サイトの送信元を変える場合は、`wp_mail_from`・`wp_mail_from_name`フィルタでサイトのドメインのアドレスにする(rules/hooks.md)。

## 送信の失敗と再送

- `wp_mail()`が`false`を返したら、Kernelのロガーに`error`で記録し、`MailSendException`を投げる。ログはスコープ`mail`で記録する。`KernelConfig`の`logChannels`に`scopes: ['mail']`のチャネルを足すと、メールのログをそのロガーにも送れる(`scopes`が空のチャネルは、スコープの付いた記録も受け取る)。
- ログの文脈には、どのメールか(`admin_notification`・`auto_reply`)とフォームのIDだけを入れる。宛先・件名・本文と、`wp_mail_failed`のエラーの文言(PHPMailerの例外の文言で、アドレスを含みうる)は入れない。
- 例外は`onComplete`の外へ投げる。FWが本番ではKernelのロガーに記録し、入力画面に汎用の送信失敗のエラーを出し、送信のトークンを消費せずに戻す。利用者はそのまま送り直せる。debugでは例外がそのまま出る。
- 送り直すと`onComplete`が最初から動く。管理者への通知が済んでから自動返信が失敗した場合、再送で管理者への通知が重複する。そのため、取りこぼせない管理者への通知を先に、重複しても害の小さい自動返信を後に送る。
- 一時ファイルは、`onComplete`が例外で終わったときは消えない。再送で同じファイルが添付される。
- `wp_mail()`は、読めない添付ファイルを黙って外し、本文だけのメールを送って成功を返す。`StoredFile::exists()`はファイルの有無だけを見るため、送る前に`is_readable()`も確かめ、読めなければ記録して`MailSendException`を投げる(管理者への通知を送る前に確かめるため、再送で重複しない)。

## してはいけないこと

- 利用者の入力を、改行を取り除かずに件名・ヘッダに入れない。
- 利用者のアドレスをFromにしない。表示名に利用者の入力を入れない。
- `wp_mail()`の戻り値を無視しない。失敗をログだけで済ませて`onComplete`を正常に終えない(利用者は送信できたと思い、問い合わせが消える)。
- ログ・例外のメッセージに、メールアドレス・氏名・本文等の入力値を入れない。
- 自動返信に利用者の自由記述を入れない。
- メールの文言に`tmswp`ドメインを使わない。日本語のソースを`__()`に渡さない。

## 確認方法

1. `composer qa`が通ること。
2. フォームを送信し、管理者への通知(Reply-Toが利用者のアドレス、添付ファイル付き)と自動返信が届くこと。開発環境ではメールを捕まえるツール(MailHog等)で確かめる。
3. 氏名に改行を含む値をPOSTしても、件名が1行のままで、ヘッダが増えないこと。
4. 送信に失敗させる(`pre_wp_mail`フィルタで`false`を返す等)と、入力画面に「The form could not be sent. Please try again.」が出て、Kernelのロガーに`mail`スコープの`error`が記録され、そのまま送り直せること。

## 発展

- フォームの定義・確認画面・完了処理: rules/form.md
- メールの送信元を変えるフィルタ(`wp_mail_from`)を置く場所: rules/hooks.md
- ログのチャネルの設定(`logChannels`): TMSWPの設計文書[docs/07-support.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/07-support.md)
