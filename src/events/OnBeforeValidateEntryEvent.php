<?php

namespace barrelstrength\sproutforms\events;

use barrelstrength\sproutforms\elements\Entry;
use barrelstrength\sproutforms\elements\Form;
use yii\base\Event;

/**
 * OnBeforeValidateEntryEvent class.
 */
class OnBeforeValidateEntryEvent extends Event
{
    /**
     * @var Form
     */
    public $form;

    /**
     * @var Entry
     */
    public $entry;

    /**
     * @var bool
     */
    public $isValid = true;

    /**
     * @var bool
     */
    public $fakeIt = false;
}
