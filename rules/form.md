# フォームを実装する

フォームの定義は`app/Form/`、テンプレートの前処理(フォームの駆動とPRGのリダイレクト)は`app/Page/`、画面はWordPress標準のページテンプレートに置く。CSRF・確認画面の値の保持・二重送信の防止・値の検証・ページキャッシュからの除外はFWに任せる。
例はテーマのもの。コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

- 例のテキストドメイン`my-theme`は、テーマのstyle.css(プラグインはプラグインヘッダ)の`Text Domain`に置き換える。アプリの文言は、ソースを英語にしてアプリのテキストドメインで`__()`に渡し、日本語は翻訳ファイルで用意する。`tmswp`ドメインはFWの文言専用で、アプリの文言には使わない。
- 対象は入力・確認・完了の3画面まで。4画面以上のウィザード形式はFWの対象外。フォームを分けて作る。
- 項目名に、WordPressの公開のクエリ変数(`name`・`s`・`p`・`page`・`year`・`author`等と、登録した投稿タイプ・タクソノミーの`query_var`、`query_vars`フィルタで足したもの)を使わない。WordPressがPOSTの値をクエリ変数として読み、送信が別のページの表示や404になるため、フロント(管理画面以外)では`handle()`・`open()`が、debugに関係なく`FormConfigException`にする。ファイルの項目は、POSTで送る`{name}__token`の名前で確かめる。お名前は`name`ではなく`full_name`等にする。

## 手順

1. `app/Form/ContactForm.php`に項目・規則・確認画面・完了処理を定義する。
2. `app/Mail/`に完了処理のメール送信を置く(rules/form-mail.md)。
3. `app/Page/ContactPage.php`に前処理を書く。フォームを駆動し、完了したら完了画面へリダイレクトする(PRG)。
4. テンプレート`page-contact.php`の先頭で`ContactPage::handle()`を呼び、入力画面と確認画面を出す。
5. 完了画面の固定ページを作る。
6. `composer qa`を通す。

## 完全な実装例

お問い合わせフォーム(13項目・確認画面あり・完了画面へのPRG・管理者への通知と自動返信のメール)。

### フォームの定義

`app/Form/ContactForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Rule\Katakana;
use App\Form\SpamGuard\HoneypotGuard;
use App\Mail\ContactMailer;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\Retype;

final class ContactForm
{
    public static function create(): Form
    {
        $phone = new Regex('/\A0\d{1,4}-?\d{1,4}-?\d{3,4}\z/', __('Please enter {label} using digits and hyphens.', 'my-theme'));

        return new Form(
            FormDefinition::create('contact')
                ->text('full_name', self::label('full_name'), [new Required(), new MaxLength(50)])
                ->text('kana', self::label('kana'), [new Required(), new Katakana(), new MaxLength(50)])
                ->text('company', self::label('company'), [new MaxLength(100)])
                ->text('email', self::label('email'), [new Required(), new Email(), new MaxLength(254)])
                ->text('email_confirm', self::label('email_confirm'), [new Required(), new Retype('email')])
                ->text('phone', self::label('phone'), [new Required(), $phone])
                ->text('address', self::label('address'), [new MaxLength(200)])
                ->select('subject', self::label('subject'), ['' => __('Please select', 'my-theme')] + self::subjects(), [new Required()])
                ->radios('contact_method', self::label('contact_method'), self::contactMethods(), [new Required()], 'email')
                ->checkboxes('products', self::label('products'), self::products())
                ->textarea('message', self::label('message'), [new Required(), new MaxLength(2000)])
                ->file('attachment', self::label('attachment'), [new FileExtension(['pdf', 'jpg', 'jpeg', 'png']), MaxFileSize::mb(5)])
                ->checkbox('privacy', self::label('privacy'), '1', [new Required(__('Please agree to the {label}.', 'my-theme'))], __('Agreed', 'my-theme'))
                ->hideOnConfirm('email_confirm')
                ->confirm()
                ->spamGuard(new HoneypotGuard())
                ->onComplete(
                    /** @param array<string, mixed> $values */
                    static function (array $values): void {
                        // 失敗は例外で知らせる。FWが記録し、利用者が送り直せる状態に戻す
                        (new ContactMailer())->send($values);
                    },
                ),
        );
    }

    /** 項目のラベル。定義とメールの本文で同じ文言を使う */
    public static function label(string $name): string
    {
        return match ($name) {
            'full_name' => __('Name', 'my-theme'),
            'kana' => __('Name (katakana)', 'my-theme'),
            'company' => __('Company', 'my-theme'),
            'email' => __('Email address', 'my-theme'),
            'email_confirm' => __('Email address (confirmation)', 'my-theme'),
            'phone' => __('Phone number', 'my-theme'),
            'address' => __('Address', 'my-theme'),
            'subject' => __('Inquiry type', 'my-theme'),
            'contact_method' => __('Preferred contact method', 'my-theme'),
            'products' => __('Products of interest', 'my-theme'),
            'message' => __('Message', 'my-theme'),
            'attachment' => __('Attachment', 'my-theme'),
            'privacy' => __('Privacy policy', 'my-theme'),
            default => throw new \LogicException("unknown contact form field: {$name}"),
        };
    }

    /** @return array<string, string> 値 => 表示 */
    public static function subjects(): array
    {
        return [
            'product' => __('About our products', 'my-theme'),
            'estimate' => __('Request a quote', 'my-theme'),
            'other' => __('Other', 'my-theme'),
        ];
    }

    /** @return array<string, string> 値 => 表示 */
    public static function contactMethods(): array
    {
        return [
            'email' => __('Email', 'my-theme'),
            'phone' => __('Phone', 'my-theme'),
        ];
    }

    /** @return array<string, string> 値 => 表示 */
    public static function products(): array
    {
        return [
            'cms' => __('CMS', 'my-theme'),
            'hosting' => __('Hosting', 'my-theme'),
            'support' => __('Support plan', 'my-theme'),
        ];
    }
}
```

- `Form`は継承しない。構成済みの`Form`を返す`create()`を持つ`final`クラスにする。
- 規則は書いた順に実行し、最初の違反で止まる(1項目のエラーは1件)。`Required`(条件付きなら`RequiredIf`)を先頭に書く。`RequiredIf`は`RequiredIf::filled('contact_method')`(ほかの項目が入力されたら必須)か`RequiredIf::equals('contact_method', 'phone')`(ほかの項目が指定の値なら必須)で作る。
- 空の値は`Required`・`RequiredIf`以外の規則を通過する。任意の項目(`company`等)は`Required`を書かないだけで「入力されたら形式を確かめる」になる。
- 選択肢のある項目(select・radios・checkboxes)と単一のチェックボックスは、選択肢に無い値をFWが自動で拒否する。`Choice`を書かなくてよい。選択肢のキー`''`はプレースホルダで、`Required`で未選択を検出する。
- 規則のメッセージは`{label}`等のプレースホルダを使う。FWの既定のメッセージ(英語のソース)は、FW同梱の翻訳で日本語になる。サイト全体で変えるときは`tmswp/form/rule_message`フィルタを使う。このフィルタが効くのは規則(Rule)のメッセージだけで、フォーム全体のエラー(`formErrors`)と、複合フィールドの`incompleteMessage()`・`invalidPartsMessage()`には効かない。
- 確認用のメールアドレスは`Retype`で一致を確かめ、`hideOnConfirm()`で確認画面に出さない。
- 単一のチェックボックスは、確認画面に出す文言を`checkedLabel`(5番目の引数)で渡す。
- 完了処理は`onComplete()`に閉じ込める。ファイルの項目の値は`StoredFile`か`''`で渡る(「ファイルを受け取る」)。

### カスタムのRule

同梱のルール(`Required`・`RequiredIf`・`MaxLength`・`MinLength`・`Email`・`Url`・`Regex`・`Choice`・`Callback`・`Retype`・`Range`・`FileExtension`・`MaxFileSize`)で足りない検査は、`Rule`を継承したクラスを`app/Form/Rule/`に置く。

`app/Form/Rule/Katakana.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form\Rule;

use TMSWP\Core\Form\Rule\Rule;
use TMSWP\Core\Form\ValidationContext;

/** 全角カタカナ(長音符と空白を含む)だけを受け付ける */
final class Katakana extends Rule
{
    protected function passes(mixed $value, ValidationContext $context): bool
    {
        return \is_string($value) && preg_match('/\A[\x{30A1}-\x{30F6}\x{30FC}\x{3000} ]+\z/u', $value) === 1;
    }

    public function defaultMessage(): string
    {
        return __('Please enter {label} in full-width katakana.', 'my-theme');
    }
}
```

- `passes()`と`defaultMessage()`を実装する。`defaultMessage()`の中で`__()`を呼ぶ(ルールを作った時点では評価しない)。
- 空の値では呼ばれない(`skipIfEmpty()`の既定が`true`)。checkboxesの項目では要素ごとに呼ばれる(`eachElement()`の既定が`true`)。
- `{label}`はFWが置き換える。ほかのプレースホルダは`messageParams()`で`['length' => 10]`のように返す。
- 個別のメッセージは、コンストラクタの引数(`new Katakana(__('...', 'my-theme'))`)で渡せる。
- 他の項目の値は`$context->value('email')`で読める。メールアドレスの重複等、WordPressの関数を使う検査も同じ形で書く。
- 1つのフォームでしか使わない検査は、クラスを作らずに`new Callback(static fn (mixed $value): bool => ...)`で書ける。

### 前処理(PRG)

`app/Page/ContactPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Page;

use App\Form\ContactForm;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;

/** page-contact.phpの前処理。お問い合わせフォームを駆動し、完了したら完了画面へリダイレクトする */
final class ContactPage
{
    private function __construct(
        public readonly Form $form,
        public readonly FormResult $result,
    ) {}

    public static function handle(): self
    {
        $form = ContactForm::create();
        // CSRF・送信トークン・スパム対策・値の検証・状態遷移・完了処理。ページキャッシュからも外す
        $result = $form->handle();

        if ($result->step === FormStep::Complete) {
            wp_safe_redirect(home_url('/contact/thanks/'), 303);
            // PRGのリダイレクトの直後だけはexitを使う(FWの設計原則1のexit禁止の例外)
            exit;
        }

        return new self($form, $result);
    }
}
```

- テンプレートの先頭で型付きのクラスを明示的に呼ぶ。`template_redirect`等のフックでテンプレートを判定してフォームを駆動しない。どのテンプレートで何を駆動するかを、テンプレートのファイルだけで追えるようにするため。
- `exit`は、FWの設計原則1(TMSWPの設計文書[docs/00-overview.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/00-overview.md)の「設計原則 5箇条」の節。テンプレートで`exit`を使わない)の例外として、PRGの`wp_safe_redirect()`の直後に限って使う。ほかの場所では使わない。
- 完了の後にリダイレクトするのは、完了画面のリロードで同じPOSTを送り直させないため。リダイレクトしない場合も、FWの二重送信の防止で完了処理は1回だけ動く(「確認画面なしのフォーム」の例)。
- プラグインのショートコードでフォームを出す場合は、出力の前のフック(`template_redirect`)で、ショートコードを含むページかを判定して`handle()`を先に呼び、PRGのリダイレクトもそこで行う。ショートコードでは同じ`Form`のインスタンスを使う(`handle()`は2回目以降、最初の結果を返す)。ブロックで出す場合は、ショートコードの判定では見つからないため、ブロックを含むページかを出力の前に判定する別の処理が要る。

### テンプレート(入力画面と確認画面)

`page-contact.php`(固定ページ`contact`のテンプレート):

```php
<?php

declare(strict_types=1);

use App\Page\ContactPage;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\StoredFile;

// 出力の前(get_header()より前)に1回だけ呼ぶ。リダイレクトとnocacheのヘッダは出力の後では効かない
$vm = ContactPage::handle();
$form = $vm->form;

get_header();
?>
<main class="contact">
    <h1><?php the_title(); ?></h1>

    <?php if ($vm->result->step === FormStep::Confirm) : ?>
        <p><?php esc_html_e('Please check your entries and press Send.', 'my-theme'); ?></p>
        <?php echo $form->open(['class' => 'contact-form']); ?>
        <dl>
            <?php foreach ($form->confirmFields() as $view) : ?>
                <dt><?php echo $view->label(); ?></dt>
                <dd><?php echo $view->display(); ?></dd>
            <?php endforeach; ?>
        </dl>
        <button type="submit" name="_step" value="back"><?php esc_html_e('Back', 'my-theme'); ?></button>
        <button type="submit" name="_step" value="submit"><?php esc_html_e('Send', 'my-theme'); ?></button>
        <?php echo $form->close(); ?>
    <?php else : ?>
        <?php foreach ($vm->result->formErrors as $error) : ?>
            <p class="form-alert"><?php echo esc_html($error); ?></p>
        <?php endforeach; ?>

        <?php echo $form->open(['class' => 'contact-form']); ?>
        <dl>
            <dt><?php echo $form->field('full_name')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('full_name')->input(['autocomplete' => 'name']); ?>
                <?php echo $form->field('full_name')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('kana')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('kana')->input(); ?>
                <?php echo $form->field('kana')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('company')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('company')->input(['autocomplete' => 'organization']); ?>
                <?php echo $form->field('company')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('email')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('email')->input(['type' => 'email', 'autocomplete' => 'email']); ?>
                <?php echo $form->field('email')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('email_confirm')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('email_confirm')->input(['type' => 'email']); ?>
                <?php echo $form->field('email_confirm')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('phone')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('phone')->input(['type' => 'tel', 'autocomplete' => 'tel']); ?>
                <?php echo $form->field('phone')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('address')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('address')->input(['autocomplete' => 'street-address']); ?>
                <?php echo $form->field('address')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('subject')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('subject')->select(); ?>
                <?php echo $form->field('subject')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('contact_method')->label(); ?></dt>
            <dd>
                <?php echo $form->field('contact_method')->radios(); ?>
                <?php echo $form->field('contact_method')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('products')->label(); ?></dt>
            <dd>
                <?php foreach ($form->field('products')->options() as $option) : ?>
                    <span class="option"><?php echo $option->input(); ?><?php echo $option->labelTag(); ?></span>
                <?php endforeach; ?>
                <?php echo $form->field('products')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('message')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('message')->textarea(['rows' => 8]); ?>
                <?php echo $form->field('message')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('attachment')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('attachment')->input(['accept' => '.pdf,.jpg,.jpeg,.png']); ?>
                <?php // 選択済みのファイル(確認画面から戻った時・ほかの項目のエラーの時)を引き回す ?>
                <?php echo $form->field('attachment')->hidden(); ?>
                <?php if ($form->field('attachment')->value() instanceof StoredFile) : ?>
                    <p><?php printf(esc_html__('Selected file: %s', 'my-theme'), $form->field('attachment')->display()); ?></p>
                <?php endif; ?>
                <?php echo $form->field('attachment')->errors(); ?>
            </dd>
        </dl>

        <p>
            <?php echo $form->field('privacy')->checkbox(); ?>
            <?php echo $form->field('privacy')->labelTag(); ?>
            <?php echo $form->field('privacy')->errors(); ?>
        </p>

        <button type="submit" name="_step" value="confirm"><?php esc_html_e('Confirm', 'my-theme'); ?></button>
        <?php echo $form->close(); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
```

- `$form->open()`は`<form method="post">`と必須のhidden(`_form_id`・`_nonce`と、確認画面の無いフォームでは`_submit_token`、確認画面では`_confirm_token`)を出す。ファイルの項目があれば`enctype="multipart/form-data"`も付く。入力画面では、登録したスパム対策のHTMLも続けて出す。`method`・`enctype`は指定できない。
- 送信ボタンは`name="_step"`にし、入力画面は`value="confirm"`、確認画面は`value="back"`と`value="submit"`にする。`_step`の無いPOSTは、検証されずに入力画面へ戻る。
- `FieldView`のHTMLを返すメソッド(`input()`・`labelTag()`・`errors()`・`label()`・`display()`等)はエスケープ済みの値を返す。そのまま`echo`する。`value()`・`errorList()`(エラーの文言のlist)・`id()`・`name()`は生の値を返すため、出力するときは`esc_html()`・`esc_attr()`でエスケープする。
- HTMLの構造とクラスはテンプレートが決める。`radios()`・`checkboxes()`は選択肢をまとめて出し、細かく組みたいときは`options()`を回す(`OptionView::$label`は生の値のため、自分で出すときは`esc_html()`する)。
- 入力部品の`id`を変えたときは、同じ値を`labelTag(['for' => ...])`にも渡す。

### 完了画面

固定ページ`contact`の子ページとして`thanks`(URLは`/contact/thanks/`)を作り、本文を管理画面で書く。専用のテンプレートは要らない。`ContactPage`のリダイレクト先はこのURLにする。

## 画面の流れ

| 利用者の操作 | `_step` | `$result->step` | 表示 |
|---|---|---|---|
| ページを開く(GET) | なし | `Input` | 入力画面 |
| 「Confirm」を押す | `confirm` | `Confirm`(エラーがあれば`Input`) | 確認画面 |
| 確認画面で「Back」を押す | `back` | `Input` | 入力画面(入力とファイルを保つ) |
| 確認画面で「Send」を押す | `submit` | `Complete`(失敗なら`Input`) | 完了画面へリダイレクト |

- 確認画面の値は、署名付きの確認トークン(`_confirm_token`)がクライアントとの間を運ぶ。セッションは使わない。確認の後の改ざんは検出され、入力画面に戻る。
- 確認トークンの有効期限は1800秒(30分)で、`FormDefinition::confirmTtl(秒)`で変えられる。期限を過ぎてから送信すると、「The confirmation screen has expired. Please review your entries and continue again.」で入力画面に戻る(入力した値は残る)。
- 送信は、確認トークンごとに1回に限られる。同じ確認画面からの送信のやり直し(二重クリック、送信のPOSTの再送、完了の後に同じ確認画面のページから送り直す)は「This form has already been submitted.」で入力画面に戻り、完了処理は動かない。
- 確認ボタンのPOSTを送り直すと(入力画面に戻って「Confirm」を押し直す、ブラウザの戻るで確認画面のPOSTを再送する等)、新しい確認トークンが発行され、その確認画面からはもう一度送信できる。これは新しい送信として扱われる。同じ内容の重複を防ぐ必要がある完了処理(申し込みの受け付け等)は、`onComplete`の中でアプリが重複を確かめる。
- `onComplete`が例外を投げたときは、debugでは例外がそのまま出る。本番ではKernelのロガーに`error`で記録され、入力画面に「The form could not be sent. Please try again.」が出る。送信のトークンは消費されないため、利用者は確認からやり直して送れる(ブラウザの再送信で同じトークンを送っても受け付ける)。`onComplete`の中の処理が一部だけ済んでから例外になった場合、再送でその処理が重複する。重複させたくない処理は冪等にするか、失敗しうる処理の後に置く(メールの順序はrules/form-mail.md)。

## 確認画面の書き方

- 確認画面は`$form->confirmFields()`を回し、`label()`と`display()`で出す。`fields()`は全項目を返すため、確認画面には使わない。
- `display()`は、選択肢をラベルに、複数選択を区切って、textareaの改行を`<br>`に、ファイルをファイル名にして、エスケープ済みで返す。
- `confirmFields()`は、hiddenとパスワードの項目と、`hideOnConfirm()`で指定した項目を除く。
- 単一のチェックボックスは、`checkedLabel`の文言を出す。`checkedLabel`の無い単一のチェックボックスの`display()`は`FormConfigException`になる。確認画面に出さないなら`hideOnConfirm()`で除く。
- パスワードの項目は、確認画面ありのフォームに置けない(定義の時点で`FormConfigException`)。確認画面の値はトークンでクライアントを往復するため。ログイン・パスワードの変更等は確認画面なしのフォームで作る。会員登録はパスワードをフォームで受け取らず、rules/user-role.mdの「認証(ログイン・会員登録)」のとおり、パスワード設定のメールで利用者に決めさせる。

## 確認画面なしのフォーム

`confirm()`を呼ばないと、入力と完了の2画面になる。送信ボタンは`name="_step" value="submit"`にする。二重送信の防止は、`open()`が埋め込む使い捨ての送信トークン(`_submit_token`)でFWが行う。

次の例は、応募書類(PDF)を受け付けるフォーム。リダイレクトせずにその場で完了画面を出す。ファイルはWebから見えない場所に一時保管し、完了処理で応募書類の保管場所へ移す。

`app/Form/EntryForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Log\Log;

final class EntryForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('entry')
                ->text('full_name', __('Name', 'my-theme'), [new Required(), new MaxLength(50)])
                ->text('email', __('Email address', 'my-theme'), [new Required(), new Email(), new MaxLength(254)])
                ->file('resume', __('Resume', 'my-theme'), [new Required(), new FileExtension(['pdf']), MaxFileSize::mb(5)])
                ->tempFileStore(self::store())
                ->onComplete(
                    /** @param array<string, mixed> $values */
                    static function (array $values): void {
                        $resume = $values['resume'] ?? null;

                        if (!$resume instanceof StoredFile) {
                            throw new \LogicException('resume must be a stored file after validation');
                        }

                        // 保存名はアプリが決める(利用者のファイル名をパスに使わない)
                        $fileName = wp_generate_uuid4() . '.pdf';
                        $resume->moveTo(self::privateDir() . '/entries', $fileName);

                        // 文脈に入力値(氏名・メールアドレス・元のファイル名)を入れない
                        Log::scope('entry')->info('an entry was saved: {file}', ['form' => 'entry', 'file' => $fileName]);
                    },
                ),
        );
    }

    /** 一時保管の場所。Webサーバーが公開しないディレクトリに置く */
    public static function store(): TempFileStore
    {
        return new TempFileStore(self::privateDir() . '/tmswp-form-tmp');
    }

    /** wp-config.phpでdefine('APP_PRIVATE_DIR', '/srv/example/private')のように指定する(公開ディレクトリの外) */
    private static function privateDir(): string
    {
        $dir = \defined('APP_PRIVATE_DIR') ? \constant('APP_PRIVATE_DIR') : null;

        if (!\is_string($dir) || $dir === '') {
            throw new \LogicException('APP_PRIVATE_DIR must be defined in wp-config.php');
        }

        return $dir;
    }
}
```

`page-entry.php`(固定ページ`entry`のテンプレート):

```php
<?php

declare(strict_types=1);

use App\Form\EntryForm;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\StoredFile;

// 出力の前に呼ぶ。完了処理もここで動く
$form = EntryForm::create();
$result = $form->handle();

get_header();
?>
<main class="entry">
    <h1><?php the_title(); ?></h1>

    <?php if ($result->step === FormStep::Complete) : ?>
        <p><?php esc_html_e('Thank you for your application. We will contact you by email.', 'my-theme'); ?></p>
    <?php else : ?>
        <?php foreach ($result->formErrors as $error) : ?>
            <p class="form-alert"><?php echo esc_html($error); ?></p>
        <?php endforeach; ?>

        <?php echo $form->open(); ?>
        <p>
            <?php echo $form->field('full_name')->labelTag(); ?>
            <?php echo $form->field('full_name')->input(['autocomplete' => 'name']); ?>
            <?php echo $form->field('full_name')->errors(); ?>
        </p>
        <p>
            <?php echo $form->field('email')->labelTag(); ?>
            <?php echo $form->field('email')->input(['type' => 'email', 'autocomplete' => 'email']); ?>
            <?php echo $form->field('email')->errors(); ?>
        </p>
        <p>
            <?php echo $form->field('resume')->labelTag(); ?>
            <?php echo $form->field('resume')->input(['accept' => '.pdf']); ?>
            <?php // 送信の失敗の後に、選び直さずに送り直せるようにする ?>
            <?php echo $form->field('resume')->hidden(); ?>
            <?php if ($form->field('resume')->value() instanceof StoredFile) : ?>
                <?php printf(esc_html__('Selected file: %s', 'my-theme'), $form->field('resume')->display()); ?>
            <?php endif; ?>
            <?php echo $form->field('resume')->errors(); ?>
        </p>
        <button type="submit" name="_step" value="submit"><?php esc_html_e('Apply', 'my-theme'); ?></button>
        <?php echo $form->close(); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
```

- パスワードの項目は、確認画面なしのフォームにだけ置ける。値は`onComplete`の`$values`で受け取る。再描画では空になる。パスワードの項目の`hidden()`・`display()`は`FormConfigException`になる。
- 送信トークンの有効期限は1日。期限切れ・改ざんは「Your session has expired. Please submit the form again.」で入力画面に戻る。

## ファイルを受け取る

- ファイルの項目は`file()`で1項目1ファイル。複数のファイルは項目を分ける。規則は`FileExtension`(拡張子と、ファイルの実体のMIMEの整合)と`MaxFileSize`を使う。
- アップロードの失敗(サイズの超過・部分的なアップロード等)は、規則を書かなくても項目のエラーになる。
- 確認画面の有無に関係なく、ファイルは値の検証を通った後に一時保管され、`onComplete`には`StoredFile`(未選択なら`''`)が渡る。
- `onComplete`でファイルを消費する。`moveTo($directory, $fileName)`で保管場所へ移す(戻り値は移した先の絶対パス。`$fileName`を省くと利用者のファイル名`clientName`になるため、省かずにアプリが決めた名前を渡す。同じ名前があれば`wp_unique_filename()`で別の名前になる)か、`toAttachment($parentPostId)`でメディアライブラリの添付ファイルにする(フロントのリクエストでも呼べる)。メールに添付するだけなら消費しなくてよい(rules/form-mail.md)。
- 消費しなかった一時ファイルは、完了の後にFWが消す。`handle()`が返した後の`StoredFile`は使えない。ファイルを扱う処理は`onComplete`の中に書く。
- `onComplete`が例外を投げたときは、一時ファイルは消えない。確認画面ありは同じ確認トークンで、確認画面なしは入力画面の`$form->field('resume')->hidden()`(`{name}__token`)で、選び直さずに送り直せる。ファイルを消費してから例外になると送り直せないため、消費は`onComplete`の最後に行う。
- 確認画面での離脱等で使われなかった一時ファイルは、WP-Cronが期限(24時間)を過ぎてから消す。WP-Cronを無効にしたサイトでは、システムのcronで`wp cron event run --due-now`を実行する。
- 入力画面には`hidden()`を置く。確認画面から戻った時と、ほかの項目のエラーで入力画面に戻った時に、選択済みのファイルを引き回す。

### 一時保管のディレクトリを公開しない

既定の一時保管の場所は`wp-content/uploads/tmswp-form-tmp/`で、Webから見える場所にある。Apacheでは、FWが置く`.htaccess`でアクセスを拒否する。nginxは`.htaccess`を読まないため、サーバーの設定で拒否する。

```nginx
# PHPの処理等の正規表現のlocationより優先させるため、^~を付ける
location ^~ /wp-content/uploads/tmswp-form-tmp/ {
    deny all;
}
```

- マルチサイトでは、サイトごとの`/wp-content/uploads/sites/<ID>/tmswp-form-tmp/`も拒否する。
- 設定の後、一時保管の場所のURL(例: `/wp-content/uploads/tmswp-form-tmp/index.html`)が403になることを確かめる。
- サーバーの設定を変えられない場合や、扱うファイルに個人情報を含む場合は、`EntryForm`のように`tempFileStore()`でWebの公開ディレクトリの外に置く。

### 独自の一時保管の場所の掃除

`tempFileStore()`で独自の場所を使う場合は、その`gc()`をWP-Cronのフック`TempFileGc::HOOK`に登録する。FWが登録するのは既定の場所の`gc()`だけである。イベントのスケジュールは、ファイルを保管したときにFWが行う。

`app/Hook/EntryFormTempFileHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\Form\EntryForm;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class EntryFormTempFileHook extends Hook
{
    /** 応募フォームの一時保管の場所から、期限(24時間)を過ぎたファイルを消す */
    #[Action(TempFileGc::HOOK)]
    public function collectGarbage(): void
    {
        EntryForm::store()->gc();
    }
}
```

## ページキャッシュとの関係

- `handle()`と`open()`は、そのページをページキャッシュから外す(`DONOTCACHEPAGE`を定義し、`nocache_headers()`を送る)。nonceと送信トークンを埋め込んだページがキャッシュに載ると、期限の切れたnonceが全員に配られ、送信がすべて失敗するため。自分で設定しなくてよい。
- `nocache_headers()`は、出力の開始後には何もしない。`handle()`はテンプレートの先頭(`get_header()`より前)で呼ぶ。
- 性能への影響: キャッシュから外れるのは、`handle()`か`open()`を呼んだページだけである。サイドバー・フッター等、全ページに出る場所にフォームを置くと、サイト全体がページキャッシュから外れる。すべてのページを毎回PHPとデータベースで作るため、表示が遅くなり、アクセスの多いサイトではサーバーの負荷が上がる。全ページに出す導線は、フォームのページへのリンクにする。
- `DONOTCACHEPAGE`はWordPressのキャッシュプラグインが見る定数である。サーバーやCDNのキャッシュ(nginxの`fastcgi_cache`等)は、`Cache-Control`を見ない設定ならフォームのページを対象から外す。

## 複合フィールド(CompositeField)を自作する

年月日を別々の`<select>`で入力させる等、複数の入力部品を1つの値として扱う場合は、`CompositeField`を継承したフィールドをapp側で定義する。`parts()`は入力部品、`compose()`は部品の値から正準値(文字列)、`decompose()`は正準値から部品の値を返す。部品がすべて空なら未入力、一部だけが空なら`incompleteMessage()`、部品が揃った後の整合は`validateParts()`で検証する。部品の選択肢に無い値(範囲外の年等)は、FWが自動で拒否する。

`app/Form/BirthDateField.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

final class BirthDateField extends CompositeField
{
    /** @param list<RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
        private readonly bool $withDay = true,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        $parts = [
            'year' => Field::select($this->name . '_year', __('Year', 'my-theme'), $this->yearChoices()),
            'month' => Field::select($this->name . '_month', __('Month', 'my-theme'), $this->rangeChoices(1, 12)),
        ];

        if ($this->withDay) {
            $parts['day'] = Field::select($this->name . '_day', __('Day', 'my-theme'), $this->rangeChoices(1, 31));
        }

        return $parts;
    }

    public function compose(array $partValues): string
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        if ($year < 1 || $month < 1 || $day < 1) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function decompose(string $value): array
    {
        if ($value === '' || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return $this->emptyParts();
        }

        $parts = [
            'year' => (string) (int) $matches[1],
            'month' => (string) (int) $matches[2],
        ];

        if ($this->withDay) {
            $parts['day'] = (string) (int) $matches[3];
        }

        return $parts;
    }

    public function display(string $value): string
    {
        $parts = $this->decompose($value);

        if ($parts['year'] === '' || $parts['month'] === '' || ($this->withDay && $parts['day'] === '')) {
            return '';
        }

        if ($this->withDay) {
            /* translators: 1: year, 2: month, 3: day */
            return sprintf(__('%1$s/%2$s/%3$s', 'my-theme'), $parts['year'], $parts['month'], $parts['day']);
        }

        /* translators: 1: year, 2: month */
        return sprintf(__('%1$s/%2$s', 'my-theme'), $parts['year'], $parts['month']);
    }

    public function validateParts(array $partValues): bool
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        return checkdate($month, $day, $year);
    }

    public function invalidPartsMessage(): string
    {
        return __('{label} is not a valid date.', 'my-theme');
    }

    /** @return array<string, string> */
    private function emptyParts(): array
    {
        $parts = ['year' => '', 'month' => ''];

        if ($this->withDay) {
            $parts['day'] = '';
        }

        return $parts;
    }

    /** @return array<array-key, string> */
    private function yearChoices(): array
    {
        $choices = ['' => '----'];
        $maxYear = $this->maxYear ?? self::currentYear();

        for ($year = $maxYear; $year >= $this->minYear; --$year) {
            $choices[(string) $year] = (string) $year;
        }

        return $choices;
    }

    /** @return array<array-key, string> */
    private function rangeChoices(int $min, int $max): array
    {
        $choices = ['' => '--'];

        for ($value = $min; $value <= $max; ++$value) {
            $choices[(string) $value] = (string) $value;
        }

        return $choices;
    }

    /** サイトのタイムゾーンでの今年(date()はUTCになるため使わない) */
    private static function currentYear(): int
    {
        $year = wp_date('Y');

        if ($year === false) {
            throw new \LogicException('failed to get the current year');
        }

        return (int) $year;
    }
}
```

- フォームには`add()`で足す: `->add(new BirthDateField('birthday', __('Date of birth', 'my-theme'), [new Required()]))`。
- `compose()`は、部品がすべて空なら`''`を返す。`decompose('')`はすべての部品を`''`にし、`compose(decompose($v)) === $v`を満たす。
- 確認画面の表示は`display()`が返す。日付の書式は翻訳ファイルで変える(日本語なら`%1$s年%2$s月%3$s日`)。

部品はテンプレートで`part()`を使って出す。`parts/birthday-field.php`:

```php
<?php

declare(strict_types=1);

/** @var array{form: \TMSWP\Core\Form\Form} $args */
$birthday = $args['form']->field('birthday');
?>
<fieldset>
    <legend><?php echo $birthday->label(); ?></legend>
    <?php echo $birthday->part('year')->select(); ?>
    <?php echo $birthday->part('month')->select(); ?>
    <?php echo $birthday->part('day')->select(); ?>
    <?php echo $birthday->errors(); ?>
</fieldset>
```

- 複合フィールドのビュー(`part()`なし)で使えるのは、`label()`(複合フィールドのラベル)・`display()`・`value()`・`errors()`・`hasError()`・`errorList()`である。`labelTag()`・`id()`・`name()`・入力部品(`select()`・`input()`等)・`hidden()`は、`part('year')`で部品のビューにしてから呼ぶ。通さずに呼ぶと`FormConfigException`になる。

## スパム対策(honeypot・Turnstile)を差し込む

スパム対策は`SpamGuardInterface`を実装したクラスをapp側に置き、`FormDefinition::spamGuard()`で登録する。複数を登録でき、登録の順に検査する。

- `render()`が返すHTMLは、入力画面の`$form->open()`がhiddenの後に出力する。確認画面には出力しない。
- `passes()`は、利用者の入力を受け取るPOST(確認画面ありは確認ボタン、なしは送信ボタン)で、CSRFと送信トークンの検証の後、値の検証の前に呼ばれる。falseを返すと、フォームは入力画面に戻り、汎用のエラー(`Your submission could not be verified. Please try again.`)を出し、Kernelのロガーにnoticeを記録する。
- 確認画面からの送信では検査しない。確認の時点で通っているため。

honeypot(人には見えない入力欄に値が入っていたらボットとして拒否する)の手本は`app/Form/SpamGuard/HoneypotGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form\SpamGuard;

use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\SpamGuardInterface;

final class HoneypotGuard implements SpamGuardInterface
{
    /** @param string $fieldName フォームの項目と重ならない名前にする */
    public function __construct(private readonly string $fieldName = 'website_url') {}

    public function render(string $formId): string
    {
        // display:noneの欄は埋めないボットがあるため、画面の外に出す。支援技術とキーボード操作の対象からも外す
        return '<div aria-hidden="true" style="position:absolute;left:-10000px;">'
            . '<input type="text" name="' . esc_attr($this->fieldName) . '" id="' . esc_attr($formId . '-' . $this->fieldName) . '" value="" tabindex="-1" autocomplete="off">'
            . '</div>';
    }

    public function passes(FormRequest $request): bool
    {
        return $request->post($this->fieldName) === '';
    }
}
```

Cloudflare Turnstile(ウィジェットのトークンをsiteverifyで検証する)の手本は`app/Form/SpamGuard/TurnstileGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form\SpamGuard;

use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\SpamGuardInterface;
use TMSWP\Core\Log\Log;

final class TurnstileGuard implements SpamGuardInterface
{
    private const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly string $siteKey,
        private readonly string $secretKey,
    ) {}

    public function render(string $formId): string
    {
        // open()は本文の中で呼ばれるため、スクリプトはフッターに出る
        wp_enqueue_script('cf-turnstile', self::SCRIPT_URL, [], null, ['strategy' => 'defer', 'in_footer' => true]);

        return '<div class="cf-turnstile" data-sitekey="' . esc_attr($this->siteKey) . '"></div>';
    }

    public function passes(FormRequest $request): bool
    {
        $token = $request->post('cf-turnstile-response');

        if (!\is_string($token) || $token === '') {
            return false;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body' => ['secret' => $this->secretKey, 'response' => $token],
        ]);

        if (is_wp_error($response)) {
            // Turnstileに問い合わせられないときは送信を受け付けず、運用で気付けるように記録する
            Log::error('Turnstile siteverify request failed: {message}', ['message' => $response->get_error_message()]);

            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        return \is_array($result) && ($result['success'] ?? null) === true;
    }
}
```

Turnstileのトークンは1回だけ使え、有効期限は300秒である。確認画面ありのフォームでは確認ボタンの時点で検証するため、確認画面からの送信でトークンは要らない。

登録の例は`app/Form/InquiryForm.php`(Turnstileのキーは呼び出し側がwp-config.phpの定数等から渡す):

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\SpamGuard\HoneypotGuard;
use App\Form\SpamGuard\TurnstileGuard;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\Required;

final class InquiryForm
{
    public static function create(string $turnstileSiteKey, string $turnstileSecretKey): Form
    {
        return new Form(
            FormDefinition::create('inquiry')
                ->text('email', __('Email address', 'my-theme'), [new Required(), new Email()])
                ->textarea('message', __('Message', 'my-theme'), [new Required()])
                ->spamGuard(new HoneypotGuard())
                ->spamGuard(new TurnstileGuard($turnstileSiteKey, $turnstileSecretKey))
        );
    }
}
```

## してはいけないこと

- `$_POST`・`$_FILES`を直接読まない。値は`onComplete`の`$values`か`FieldView`から読む。
- 確認画面の値をhiddenで引き回さない。確認トークンが運ぶ。項目の`hidden()`はファイルの引き回し(`{name}__token`)とhiddenの項目に使う。
- セッションでフォームの状態を持たない。
- `handle()`を出力の後(`get_header()`の後、ショートコードの中等)で初めて呼ばない。PRGのリダイレクトとnocacheのヘッダが効かない。
- テンプレートに置くフォームを、`template_redirect`等のフックでテンプレートを判定して駆動しない。テンプレートの先頭で前処理のクラスを明示的に呼ぶ。
- PRGの`wp_safe_redirect()`の直後以外で`exit`を使わない。
- 完了処理をテンプレートで`$result->step === FormStep::Complete`を見て書かない。`onComplete`に書く(一時ファイルは`handle()`の中で消える。PRGでは完了の後にテンプレートが描画されない)。
- `onComplete`の中で例外を握りつぶさない。失敗は例外にする。FWが記録し、送り直せる状態に戻す。
- パスワードの項目を確認画面ありのフォームに置かない。
- 全ページに出るサイドバー・フッター等にフォームを置かない。サイト全体がページキャッシュから外れる。
- 一時保管のディレクトリをWebから見える状態にしない(nginxでは設定が要る)。
- 利用者のファイル名を保存先のパスに使わない。`moveTo()`の第2引数(保存名)を省かずにアプリが決め、保存先は戻り値のパスを使う。
- アプリの文言に`tmswp`ドメインを使わない。日本語のソースを`__()`に渡さない。

## 確認方法

1. `composer qa`が通ること。
2. 空のまま「Confirm」を押すと、必須の項目にエラーが出ること。
3. 入力→確認→送信で`/contact/thanks/`へリダイレクトされ、メールが届くこと(rules/form-mail.md)。
4. 確認画面の「Back」で、入力と添付ファイルが残っていること。
5. 確認画面の「Send」を素早く2回押しても、メールが1通だけ届くこと(2回目の送信は「This form has already been submitted.」で拒否される)。
6. フォームのページのレスポンスに`Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`が付いていること。
7. nginxでは、一時保管の場所のURLが403になること。

## 発展

- 完了処理のメール送信: rules/form-mail.md
- テンプレートの前処理クラスの書き方: rules/page.md
- フリガナの項目(カタカナ・ひらがなの正規化と検証): vendor/tmswp/ja-toolkit/rules/ja-toolkit.md(導入はrules/extensions.md)
- 管理画面の設定ページのフォーム: vendor/tmswp/settings-page/rules/settings-page.md
- フォームの仕様: TMSWPの設計文書[docs/05-form.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/05-form.md)
