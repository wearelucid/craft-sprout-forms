<?php

namespace barrelstrength\sproutforms\captchas;

use barrelstrength\sproutforms\base\Captcha;
use barrelstrength\sproutforms\events\OnBeforeSaveEntryEvent;
use Craft;
use ReflectionException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Class HoneypotCaptcha
 */
class HoneypotCaptcha extends Captcha
{
    /**
     * @var string
     */
    public $honeypotFieldName;

    /**
     * @var string
     */
    public $honeypotScreenReaderMessage;

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Honeypot Captcha';
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return Craft::t('sprout-forms', 'Block form submissions by robots who auto-fill all of your form fields ');
    }

    /**
     * @inheritdoc
     * @return string
     * @throws ReflectionException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function getCaptchaSettingsHtml(): string
    {
        $settings = $this->getSettings();

        $html = Craft::$app->getView()->renderTemplate(
            'sprout-forms/_components/captchas/honeypot/settings', [
            'settings' => $settings,
            'captchaId' => $this->getCaptchaId()
        ]);

        return $html;
    }

    /**
     * @inheritdoc
     * @throws ReflectionException
     * @throws ReflectionException
     */
    public function getCaptchaHtml(): string
    {
        $this->honeypotFieldName = $this->getHoneypotFieldName();
        $this->honeypotScreenReaderMessage = $this->getHoneypotScreenReaderMessage();

        $uniqueId = uniqid($this->honeypotFieldName, false);

        $html = '
    <div id="'.$uniqueId.'_wrapper" style="display:none;">
        <label for="'.$uniqueId.'">'.$this->honeypotScreenReaderMessage.'</label>
        <input type="text" id="'.$uniqueId.'" name="'.$uniqueId.'" value="" />
    </div>';

        return $html;
    }

    /**
     * @inheritdoc
     * @throws ReflectionException
     */
    public function verifySubmission(OnBeforeSaveEntryEvent $event): bool
    {
        $honeypotFieldName = $this->getHoneypotFieldName();

        $honeypotValue = null;

        foreach ($_POST as $key => $value) {
            // Fix issue on multiple forms on same page
            if (strpos($key, $honeypotFieldName) === 0) {
                $honeypotValue = $_POST[$key];
                break;
            }
        }

        // The honeypot field must be left blank
        if ($honeypotValue) {

            Craft::error('A form submission failed the Honeypot test.', __METHOD__);

            $event->isValid = false;
            $event->fakeIt = true;

            return false;
        }

        return true;
    }

    /**
     * @return string
     * @throws ReflectionException
     */
    public function getHoneypotFieldName(): string
    {
        $settings = $this->getSettings();

        return $settings['honeypotFieldName'] ?? 'beesknees';
    }

    /**
     * @return string
     * @throws ReflectionException
     */
    public function getHoneypotScreenReaderMessage(): string
    {
        $settings = $this->getSettings();

        return $settings['honeypotScreenReaderMessage'] ?? Craft::t('sprout-forms', 'Leave this field blank');
    }
}



