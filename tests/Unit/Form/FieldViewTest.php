<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use Brain\Monkey\Functions;
use Closure;
use Error;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\FieldType;
use TMSWP\Core\Form\FieldView;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormHtml;
use TMSWP\Core\Form\OptionView;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Testing\UnitTestCase;
use WP;

/**
 * FieldView・OptionViewと、それらが使うFormHtmlの描画(docs/05 §FieldView / OptionView・§FormHtml)。
 */
final class FieldViewTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp']);

        parent::tearDown();
    }

    public function testFormHtmlAndFieldViewEscapeRenderedMarkup(): void
    {
        $this->stubEscapers();

        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->select('pref', 'Prefecture', ['tokyo' => '<Tokyo>']);
        $form = new Form($definition);

        self::assertSame(
            '<input type="text" name="name" value="" id="contact-name" data-label="&lt;name&gt;">',
            $form->field('name')->input(['data-label' => '<name>']),
        );
        self::assertSame(
            '<select name="pref" id="contact-pref"><option value="tokyo">&lt;Tokyo&gt;</option></select>',
            $form->field('pref')->select(),
        );
    }

    public function testFieldViewRendersErrorsOptionsAndFileToken(): void
    {
        $this->stubEscapers();

        $checkboxes = new FieldView(
            Field::checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs']),
            ['docs'],
            ['Please choose.'],
            'contact',
            '<span>%s</span>',
        );
        $file = new FieldView(
            Field::file('resume', 'Resume'),
            new StoredFile('0123456789abcdef0123456789abcdef', '/tmp/resume.pdf', 'resume.pdf', 'application/pdf', 10),
            [],
            'contact',
            '<span>%s</span>',
        );

        self::assertSame('<span>Please choose.</span>', $checkboxes->errors());
        self::assertSame('Docs', $checkboxes->display());
        self::assertStringContainsString('name="topics[]" value="docs" checked', $checkboxes->checkboxes());
        self::assertSame('<input type="hidden" name="resume__token" value="0123456789abcdef0123456789abcdef">', $file->hidden());
    }

    public function testOptionViewRendersRadioAndLabel(): void
    {
        $this->stubEscapers();

        $option = new OptionView('yes', 'Agree', true, 'contact-agree-0', 'agree', FieldType::Radios);

        self::assertSame('<input type="radio" name="agree" value="yes" checked id="contact-agree-0">', $option->input());
        self::assertSame('<label for="contact-agree-0">Agree</label>', $option->labelTag());
    }

    public function testFieldViewRejectsInvalidAttributeValue(): void
    {
        $this->expectException(FormConfigException::class);

        (new FieldView(Field::text('name', 'Name'), '', [], 'contact', '%s'))->input(['value' => new stdClass()]);
    }

    public function testEveryTextNodeIsEscapedWithEscHtml(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        // esc_html のフィルタを通ったことが分かる印を付ける
        Functions\when('esc_html')->alias(static fn(string $value): string => '[' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ']');
        $definition = FormDefinition::create('contact')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->radios('color', 'Color', ['red' => 'Red'])
            ->checkbox('agree', 'Agree', checkedLabel: 'Yes');
        $form = new Form($definition);
        $message = new FieldView(Field::textarea('message', 'Message'), '<b>', ['Too <long>'], 'contact', '<p>%s</p>');

        self::assertSame('<textarea name="message" id="contact-message">[&lt;b&gt;]</textarea>', $message->textarea());
        self::assertSame('<p>[Too &lt;long&gt;]</p>', $message->errors());
        self::assertSame('[&lt;b&gt;]', $message->display());
        self::assertSame('[Message]', $message->label());
        self::assertSame('<label for="contact-message">[Message]</label>', $message->labelTag());
        self::assertStringContainsString('<option value="basic">[Basic]</option>', $form->field('plan')->select());
        self::assertStringEndsWith(' [Red]</label>', $form->field('color')->radios());
        self::assertSame('<label for="contact-color-0">[Red]</label>', $form->field('color')->options()[0]->labelTag());
    }

    /**
     * esc_html が無い環境で、別のエスケープ(htmlspecialchars)に黙って切り替えない。WordPress の esc_html フィルタを
     * 通らない出力の経路を残さないため。
     */
    #[RunInSeparateProcess]
    public function testFormHtmlDoesNotFallBackToAnotherEscaperWithoutEscHtml(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        self::assertFalse(\function_exists('esc_html'));

        $this->expectException(Error::class);
        $this->expectExceptionMessage('esc_html');

        FormHtml::label('<Name>', 'contact-name');
    }

    public function testIdOfControlsAndForOfLabelsCanBeOverridden(): void
    {
        $this->stubEscapers();
        $form = new Form(FormDefinition::create('contact')
            ->text('name', 'Name')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->checkbox('agree', 'Agree')
            ->radios('color', 'Color', ['red' => 'Red']));

        self::assertSame('<input type="email" name="name" value="" id="custom-name" class="wide">', $form->field('name')->input(['type' => 'email', 'id' => 'custom-name', 'class' => 'wide']));
        self::assertSame('<label for="custom-name">Name</label>', $form->field('name')->labelTag(['for' => 'custom-name']));
        // HTML の属性名は大文字と小文字を区別しない
        self::assertSame('<input type="text" name="name" value="" id="upper-name">', $form->field('name')->input(['ID' => 'upper-name']));
        self::assertSame('<textarea name="message" id="custom-message"></textarea>', $form->field('message')->textarea(['id' => 'custom-message']));
        self::assertStringStartsWith('<select name="plan" id="custom-plan">', $form->field('plan')->select(['id' => 'custom-plan']));
        self::assertSame('<input type="checkbox" name="agree" value="1" id="custom-agree" required>', $form->field('agree')->checkbox(['id' => 'custom-agree', 'required']));
        $option = $form->field('color')->options()[0];
        self::assertSame('<input type="radio" name="color" value="red" id="custom-red">', $option->input(['id' => 'custom-red']));
        self::assertSame('<label for="custom-red">Red</label>', $option->labelTag(['for' => 'custom-red']));
        self::assertSame('contact-name', $form->field('name')->id());
    }

    /** @return iterable<string, array{Closure(Form): string}> */
    public static function attributesSetByTheForm(): iterable
    {
        yield 'name of input' => [static fn(Form $form): string => $form->field('name')->input(['name' => 'other'])];
        yield 'name of input in upper case' => [static fn(Form $form): string => $form->field('name')->input(['NAME' => 'other'])];
        yield 'name of textarea' => [static fn(Form $form): string => $form->field('message')->textarea(['name' => 'other'])];
        yield 'name of select' => [static fn(Form $form): string => $form->field('plan')->select(['name' => 'other'])];
        yield 'multiple of select' => [static fn(Form $form): string => $form->field('plan')->select(['multiple'])];
        yield 'value of checkbox' => [static fn(Form $form): string => $form->field('agree')->checkbox(['value' => 'yes'])];
        yield 'checked of checkbox' => [static fn(Form $form): string => $form->field('agree')->checkbox(['checked' => true])];
        yield 'type of option' => [static fn(Form $form): string => $form->field('color')->options()[0]->input(['type' => 'checkbox'])];
        yield 'value of option' => [static fn(Form $form): string => $form->field('color')->options()[0]->input(['value' => 'blue'])];
        yield 'id of all options' => [static fn(Form $form): string => $form->field('color')->radios(['id' => 'color'])];
        yield 'for of all option labels' => [static fn(Form $form): string => $form->field('color')->radios(labelAttrs: ['for' => 'color'])];
        yield 'value of password' => [static fn(Form $form): string => $form->field('secret')->input(['value' => 'shown'])];
        yield 'value of file' => [static fn(Form $form): string => $form->field('upload')->input(['value' => 'x.pdf'])];
        yield 'the same attribute twice' => [static fn(Form $form): string => $form->field('name')->input(['id' => 'a', 'ID' => 'b'])];
        yield 'for of FormHtml::label' => [static fn(Form $form): string => FormHtml::label('Name', 'contact-name', ['for' => 'other'])];
        yield 'method of form' => [static fn(Form $form): string => $form->open(['method' => 'get'])];
        yield 'enctype of form' => [static fn(Form $form): string => $form->open(['enctype' => 'text/plain'])];
    }

    /** @param Closure(Form): string $render */
    #[DataProvider('attributesSetByTheForm')]
    public function testAttributesSetByTheFormCannotBeSpecified(Closure $render): void
    {
        $this->stubEscapers();
        // フロントの要求。Formはopen()で、POSTで送る名前を公開のクエリ変数と照らす(照合はFormRuntimeTest)
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = [];
        $GLOBALS['wp'] = $wp;
        Functions\when('nocache_headers')->justReturn();
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        $form = new Form(FormDefinition::create('contact')
            ->text('name', 'Name')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->checkbox('agree', 'Agree')
            ->radios('color', 'Color', ['red' => 'Red'])
            ->password('secret', 'Password')
            ->file('upload', 'Upload'));

        $this->expectException(FormConfigException::class);

        $render($form);
    }

    public function testPasswordIsNeitherRedisplayedNorDisplayed(): void
    {
        $this->stubEscapers();
        $password = new FieldView(Field::password('secret', 'Password'), 'p@ss<word>', [], 'contact', '%s');

        self::assertSame('<input type="password" name="secret" value="" id="contact-secret">', $password->input());
        self::assertSame('<input type="text" name="secret" value="" id="contact-secret">', $password->input(['type' => 'text']));

        foreach (['hidden' => static fn(): string => $password->hidden(), 'display' => static fn(): string => $password->display()] as $method => $render) {
            try {
                $render();
                self::fail("{$method}() rendered the password");
            } catch (FormConfigException $exception) {
                self::assertStringContainsString("password field 'secret'", $exception->getMessage());
            }
        }
    }

    public function testCheckboxIsDisplayedWithItsCheckedLabel(): void
    {
        $this->stubEscapers();
        $agree = Field::checkbox('agree', 'Agree', checkedLabel: 'I <agree>');

        self::assertSame('I &lt;agree&gt;', (new FieldView($agree, '1', [], 'contact', '%s'))->display());
        self::assertSame('', (new FieldView($agree, '', [], 'contact', '%s'))->display());
        self::assertSame('I agree', (new FieldView(Field::checkbox('agree', 'Agree', 'yes', checkedLabel: 'I agree'), 'yes', [], 'contact', '%s'))->display());
        self::assertSame('I agree', (new FieldView(Field::checkbox('agree', 'Agree', checkedLabel: 'I agree')->withRules(), '1', [], 'contact', '%s'))->display());

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage("checkbox field 'agree' needs checkedLabel to be displayed");

        (new FieldView(Field::checkbox('agree', 'Agree'), '1', [], 'contact', '%s'))->display();
    }

    public function testErrorTemplateMayContainPercentSigns(): void
    {
        $this->stubEscapers();
        $view = new FieldView(Field::text('name', 'Name'), '', ['Use 100% or %s'], 'contact', '<p style="width:100%">%s</p>');

        self::assertSame('<p style="width:100%">Use 100% or %s</p>', $view->errors());
        self::assertSame('<b>%d Use 100% or %s</b>', $view->errors('<b>%d %s</b>'));

        $this->expectException(FormConfigException::class);

        $view->errors('<p>no placeholder</p>');
    }

    public function testChoicesWithNumericKeysAreComparedAsStrings(): void
    {
        $this->stubEscapers();
        // PHP は '1' のような数字の文字列のキーを int にする
        $field = Field::select('size', 'Size', ['1' => 'Small', '2' => 'Large']);

        self::assertSame([1 => 'Small', 2 => 'Large'], $field->choices);
        self::assertStringContainsString('<option value="2" selected>Large</option>', (new FieldView($field, '2', [], 'contact', '%s'))->select());
        self::assertSame('Large', (new FieldView($field, '2', [], 'contact', '%s'))->display());
    }

    /**
     * 複合フィールドのビュー(part()なし)の label() は複合フィールドのラベルを返す。確認画面で confirmFields() を回して
     * label() と display() を出せるようにするため。labelTag()・id()・name() は対象の入力部品が1つに決まらないため、
     * part() を求める。
     */
    public function testCompositeFieldViewHasItsLabelButRequiresPartForControls(): void
    {
        $this->stubEscapers();
        $view = new FieldView(new FieldViewTestNameField('full_name', 'Full <name>'), 'Yamada Taro', [], 'contact', '%s');

        self::assertSame('Full &lt;name&gt;', $view->label());
        self::assertSame('Family &lt;name&gt;', $view->part('family')->label());
        self::assertSame('<label for="contact-full_name-family">Family &lt;name&gt;</label>', $view->part('family')->labelTag());

        $renders = [
            'labelTag()' => static fn(): string => $view->labelTag(),
            'id()' => static fn(): string => $view->id(),
            'name()' => static fn(): string => $view->name(),
        ];

        foreach ($renders as $method => $render) {
            try {
                $render();
                self::fail("{$method} of the composite field view did not require part()");
            } catch (FormConfigException $exception) {
                self::assertSame('composite field requires part() before rendering input controls', $exception->getMessage(), $method);
            }
        }
    }

    private function stubEscapers(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }
}

final class FieldViewTestNameField extends CompositeField
{
    public function parts(): array
    {
        return [
            'family' => Field::text($this->name . '_family', 'Family <name>'),
            'given' => Field::text($this->name . '_given', 'Given name'),
        ];
    }

    public function compose(array $partValues): string
    {
        return ($partValues['family'] ?? '') . ' ' . ($partValues['given'] ?? '');
    }

    public function decompose(string $value): array
    {
        [$family, $given] = array_pad(explode(' ', $value, 2), 2, '');

        return ['family' => $family, 'given' => $given];
    }
}
