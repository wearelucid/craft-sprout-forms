<?php

namespace barrelstrength\sproutforms\services;

use barrelstrength\sproutforms\base\FormField;
use barrelstrength\sproutforms\elements\Form;
use barrelstrength\sproutforms\fields\formfields\Address;
use barrelstrength\sproutforms\fields\formfields\FileUpload;
use barrelstrength\sproutforms\fields\formfields\Categories;
use barrelstrength\sproutforms\fields\formfields\Checkboxes;
use barrelstrength\sproutforms\fields\formfields\Dropdown;
use barrelstrength\sproutforms\fields\formfields\Email;
use barrelstrength\sproutforms\fields\formfields\EmailDropdown;
use barrelstrength\sproutforms\fields\formfields\Hidden;
use barrelstrength\sproutforms\fields\formfields\Invisible;
use barrelstrength\sproutforms\fields\formfields\MultiSelect;
use barrelstrength\sproutforms\fields\formfields\Name;
use barrelstrength\sproutforms\fields\formfields\Number;
use barrelstrength\sproutforms\fields\formfields\OptIn;
use barrelstrength\sproutforms\fields\formfields\Paragraph;
use barrelstrength\sproutforms\fields\formfields\Phone;
use barrelstrength\sproutforms\fields\formfields\MultipleChoice;
use barrelstrength\sproutforms\fields\formfields\RegularExpression;
use barrelstrength\sproutforms\fields\formfields\PrivateNotes;
use barrelstrength\sproutforms\fields\formfields\Entries;
use barrelstrength\sproutforms\fields\formfields\CustomHtml;
use barrelstrength\sproutforms\fields\formfields\SectionHeading;
use barrelstrength\sproutforms\fields\formfields\Tags;
use barrelstrength\sproutforms\fields\formfields\Url;
use barrelstrength\sproutforms\elements\Form as FormElement;
use barrelstrength\sproutforms\events\RegisterFieldsEvent;
use Craft;
use craft\base\FieldInterface;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\records\FieldLayoutField;
use craft\helpers\StringHelper;
use craft\base\Field;
use craft\records\Field as FieldRecord;
use barrelstrength\sproutforms\fields\formfields\SingleLine;
use craft\records\FieldLayoutField as FieldLayoutFieldRecord;
use craft\records\FieldLayoutTab as FieldLayoutTabRecord;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * @property mixed $defaultTabName
 * @property array $registeredFieldsByGroup
 */
class Fields extends Component
{
    /**
     * @var FormField[]
     */
    protected $registeredFields;

    /**
     * @event RegisterFieldsEvent The event that is triggered when registering the fields available.
     */
    const EVENT_REGISTER_FIELDS = 'registerFieldsEvent';

    /**
     * @param $fieldIds
     *
     * @return bool
     * @throws Exception
     */
    public function reorderFields($fieldIds): bool
    {
        $transaction = Craft::$app->db->getTransaction() === null ? Craft::$app->db->beginTransaction() : null;

        try {
            foreach ($fieldIds as $fieldOrder => $fieldId) {
                $fieldLayoutFieldRecord = $this->getFieldLayoutFieldRecordByFieldId($fieldId);
                $fieldLayoutFieldRecord->sortOrder = $fieldOrder + 1;
                $fieldLayoutFieldRecord->save();
            }

            if ($transaction !== null) {
                $transaction->commit();
            }
        } catch (Exception $e) {

            if ($transaction !== null) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return true;
    }

    /**
     * @param int $fieldId
     *
     * @return FieldLayoutFieldRecord
     * @throws Exception
     */
    protected function getFieldLayoutFieldRecordByFieldId($fieldId = null): FieldLayoutFieldRecord
    {
        if ($fieldId) {
            /** @var FieldLayoutFieldRecord $fieldLayoutFieldRecord */
            $fieldLayoutFieldRecord = FieldLayoutFieldRecord::find()
                ->where('fieldId=:fieldId', [
                    ':fieldId' => $fieldId
                ]);

            if (!$fieldLayoutFieldRecord) {
                throw new Exception('No field exists with the ID '.$fieldId);
            }

            return $fieldLayoutFieldRecord;
        }

        return new FieldLayoutFieldRecord();
    }

    /**
     * @return FormField[]|array
     */
    public function getRegisteredFields(): array
    {
        if (null === $this->registeredFields) {
            $this->registeredFields = [];

            // Our fields are registered in the SproutForms main class
            $event = new RegisterFieldsEvent([
                'fields' => []
            ]);

            $this->trigger(self::EVENT_REGISTER_FIELDS, $event);

            $fields = $event->fields;

            /**
             * @var FormField $instance
             */
            foreach ($fields as $instance) {
                $this->registeredFields[get_class($instance)] = $instance;
            }
        }

        return $this->registeredFields;
    }

    /**
     * @return array
     */
    public function getRegisteredFieldsByGroup(): array
    {
        $standardLabel = Craft::t('sprout-forms', 'Standard Fields');
        $specialLabel = Craft::t('sprout-forms', 'Special Fields');
        $relationsLabel = Craft::t('sprout-forms', 'Relations Fields');

        // Standard
        $groupedFields[$standardLabel][] = SingleLine::class;
        $groupedFields[$standardLabel][] = Paragraph::class;
        $groupedFields[$standardLabel][] = MultipleChoice::class;
        $groupedFields[$standardLabel][] = Dropdown::class;
        $groupedFields[$standardLabel][] = Checkboxes::class;
        $groupedFields[$standardLabel][] = Number::class;
        $groupedFields[$standardLabel][] = FileUpload::class;
        $groupedFields[$standardLabel][] = SectionHeading::class;

        // Special
        $groupedFields[$specialLabel][] = Name::class;
        $groupedFields[$specialLabel][] = Email::class;
        $groupedFields[$specialLabel][] = EmailDropdown::class;
        $groupedFields[$specialLabel][] = Phone::class;
        $groupedFields[$specialLabel][] = Url::class;
        $groupedFields[$specialLabel][] = Address::class;
        $groupedFields[$specialLabel][] = CustomHtml::class;
        $groupedFields[$specialLabel][] = PrivateNotes::class;
        $groupedFields[$specialLabel][] = MultiSelect::class;
        $groupedFields[$specialLabel][] = Hidden::class;
        $groupedFields[$specialLabel][] = Invisible::class;
        $groupedFields[$specialLabel][] = RegularExpression::class;
        $groupedFields[$specialLabel][] = OptIn::class;

        // Relations
        $groupedFields[$relationsLabel][] = Categories::class;
        $groupedFields[$relationsLabel][] = Entries::class;
        $groupedFields[$relationsLabel][] = Tags::class;

        return $groupedFields;
    }

    /**
     * @param $type
     *
     * @return FormField|null
     */
    public function getRegisteredField($type)
    {
        $fields = $this->getRegisteredFields();

        foreach ($fields as $field) {
            if ($field->getType() == $type) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Returns the value of a given field
     *
     * @param string $field
     * @param string $value
     *
     * @return FieldRecord|null
     */
    public function getFieldValue($field, $value)
    {
        return FieldRecord::findOne([
            $field => $value
        ]);
    }

    /**
     * Create a sequential string for the "name" and "handle" fields if they are already taken
     *
     * @param $field
     * @param $value
     *
     * @return null|string|string[]
     */
    public function getFieldAsNew($field, $value)
    {
        $newField = null;
        $i = 1;
        $band = true;

        do {
            if ($field == 'handle') {
                // Append a number to our handle to ensure it is unique
                $newField = $value.$i;

                $form = $this->getFieldValue($field, $newField);

                if (null === $form) {
                    $band = false;
                }
            } else {
                // Add spaces before any capital letters in our name
                $newField = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);
                $band = false;
            }

            $i++;
        } while ($band);

        return $newField;
    }

    /**
     * This service allows create a default tab given a form
     *
     * @param Form                      $form
     * @param Field|FieldInterface|null $field
     *
     * @return null
     * @throws Throwable
     */
    public function addDefaultTab(Form $form, &$field = null)
    {
        if (!$form) {
            return null;
        }

        if ($field === null) {
            $fieldsService = Craft::$app->getFields();
            $handle = $this->getFieldAsNew('handle', 'defaultField');

            $field = $fieldsService->createField([
                'type' => SingleLine::class,
                'name' => Craft::t('sprout-forms', 'Default Field'),
                'handle' => $handle,
                'instructions' => '',
                'translationMethod' => Field::TRANSLATION_METHOD_NONE,
            ]);
            // Save our field
            Craft::$app->content->fieldContext = $form->getFieldContext();
            Craft::$app->fields->saveField($field);
        }

        // Create a tab
        $tabName = $this->getDefaultTabName();
        $requiredFields = [];
        $postedFieldLayout = [];

        // Add our new field
        if ($field !== null && $field->id != null) {
            $postedFieldLayout[$tabName][] = $field->id;
        }

        $fieldLayout = Craft::$app->fields->assembleLayout($postedFieldLayout, $requiredFields);

        $fieldLayout->type = FormElement::class;

        // Set the tab to the form
        $form->setFieldLayout($fieldLayout);

        return $form;
    }

    /**
     * This service allows duplicate fields from Layout
     *
     * @param Form $form
     * @param      $postFieldLayout
     *
     * @return FieldLayout|null
     * @throws Throwable
     */
    public function getDuplicateLayout(Form $form, FieldLayout $postFieldLayout)
    {
        if (!$form || !$postFieldLayout) {
            return null;
        }

        $postedFieldLayout = [];
        $requiredFields = [];

        /** @var FieldLayoutTab[] $tabs */
        $tabs = $postFieldLayout->getTabs();

        foreach ($tabs as $tab) {
            /** @var Field[] $fieldLayoutFields */
            $fieldLayoutFields = $tab->getFields();
            $fields = [];

            foreach ($fieldLayoutFields as $fieldLayoutField) {

                /** @var Field $field */
                $field = Craft::$app->getFields()->createField([
                    'type' => get_class($fieldLayoutField),
                    'name' => $fieldLayoutField->name,
                    'handle' => $fieldLayoutField->handle,
                    'instructions' => $fieldLayoutField->instructions,
                    'required' => $fieldLayoutField->required,
                    'settings' => $fieldLayoutField->getSettings()
                ]);

                Craft::$app->content->fieldContext = $form->getFieldContext();
                Craft::$app->content->contentTable = $form->getContentTable();

                // Save duplicate field
                Craft::$app->fields->saveField($field);

                $fields[] = $field;

                if ($field->required) {
                    $requiredFields[] = $field->id;
                }
            }

            foreach ($fields as $field) {
                // Add our new field
                if ($field !== null && $field->id != null) {
                    $postedFieldLayout[$tab->name][] = $field->id;
                }
            }
        }

        // Set the field layout
        $fieldLayout = Craft::$app->fields->assembleLayout($postedFieldLayout, $requiredFields);

        $fieldLayout->type = FormElement::class;

        return $fieldLayout;
    }

    /**
     * This service allows add a field to a current FieldLayoutFieldRecord
     *
     * @param Field       $field
     * @param FormElement $form
     * @param int         $tabId
     * @param int         $nextId the next field Id
     *
     * @return boolean
     */
    public function addFieldToLayout($field, $form, $tabId, $nextId = null): bool
    {
        $response = false;
        $sortOrder = null;

        if ($field !== null && $form !== null) {
            // Let's try to order the field where is dropped

            if ($nextId) {
                $fieldLayoutFieldNext = FieldLayoutFieldRecord::findOne([
                    'tabId' => $tabId, 'layoutId' => $form->fieldLayoutId, 'fieldId' => $nextId
                ]);

                if ($fieldLayoutFieldNext) {
                    $fieldLayoutFields = FieldLayoutFieldRecord::find()
                        ->where([
                            'tabId' => $tabId, 'layoutId' => $form->fieldLayoutId

                        ])
                        ->andWhere(['>=', 'sortOrder', $fieldLayoutFieldNext->sortOrder])
                        ->all();

                    $sortOrder = $fieldLayoutFieldNext->sortOrder;

                    foreach ($fieldLayoutFields as $fieldLayoutFieldRecord) {
                        ++$fieldLayoutFieldRecord->sortOrder;
                        $fieldLayoutFieldRecord->save();
                    }
                }
            }

            if (null === $sortOrder) {
                $fieldLayoutFields = FieldLayoutFieldRecord::findAll([
                    'tabId' => $tabId, 'layoutId' => $form->fieldLayoutId
                ]);
                // At last
                $sortOrder = count($fieldLayoutFields) + 1;
            }

            $fieldRecord = new FieldLayoutFieldRecord();
            $fieldRecord->layoutId = $form->fieldLayoutId;
            $fieldRecord->tabId = $tabId;
            $fieldRecord->fieldId = $field->id;
            $fieldRecord->required = 0;
            $fieldRecord->sortOrder = $sortOrder;

            $response = $fieldRecord->save(false);
        }

        return $response;
    }

    /**
     * This service allows update a field to a current FieldLayoutFieldRecord
     *
     * @param Field       $field
     * @param FormElement $form
     * @param int         $tabId
     *
     * @return boolean
     */
    public function updateFieldToLayout($field, $form, $tabId): bool
    {
        $response = false;

        if ($field !== null && $form !== null) {
            $fieldRecord = FieldLayoutFieldRecord::findOne([
                'fieldId' => $field->id,
                'layoutId' => $form->fieldLayoutId
            ]);

            if ($fieldRecord) {
                $fieldRecord->tabId = $tabId;

                $response = $fieldRecord->save(false);
            } else {
                Craft::error('Unable to find the FieldLayoutFieldRecord', __METHOD__);
            }
        }

        return $response;
    }

    public function getDefaultTabName(): string
    {
        return Craft::t('sprout-forms', 'Tab 1');
    }

    /**
     * Loads the sprout modal field via ajax.
     *
     * @param FormElement $form
     * @param FormField   $field
     * @param null        $tabId
     *
     * @return array
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws InvalidConfigException
     */
    public function getModalFieldTemplate(Form $form, $field = null, $tabId = null): array
    {
        $fieldsService = Craft::$app->getFields();
        $request = Craft::$app->getRequest();

        $data = [];
        $data['tabId'] = null;
        $data['field'] = $fieldsService->createField(SingleLine::class);

        if ($field) {
            $data['field'] = $field;
            $tabIdByPost = $request->getBodyParam('tabId');

            if ($tabIdByPost !== null) {
                $data['tabId'] = $tabIdByPost;
            } else if ($tabId != null) //edit field
            {
                $data['tabId'] = $tabId;
            }

            if ($field->id != null) {
                $data['fieldId'] = $field->id;
            }
        }

        $data['sections'] = $form->getFieldLayout()->getTabs();
        $data['form'] = $form;
        $data['fieldClass'] = $data['field'] ? get_class($data['field']) : null;
        $view = Craft::$app->getView();

        $html = $view->renderTemplate('sprout-forms/forms/_editFieldModal', $data);
        $js = $view->getBodyHtml();
        $css = $view->getHeadHtml();

        return [
            'html' => $html,
            'js' => $js,
            'css' => $css
        ];
    }

    /**
     * @param $type
     * @param $form
     *
     * @return FieldInterface
     * @throws Throwable
     */
    public function createDefaultField($type, Form $form): FieldInterface
    {
        /** @var FieldInterface $instanceField */
        $instanceField = new $type;
        $fieldsService = Craft::$app->getFields();
        // get the field name and remove spaces
        $fieldName = preg_replace('/\s+/', '', $instanceField::displayName());
        $handleName = StringHelper::toCamelCase(lcfirst($fieldName));

        $name = $this->getFieldAsNew('name', $fieldName);
        $handle = $this->getFieldAsNew('handle', $handleName);

        $field = $fieldsService->createField([
            'type' => $type,
            'name' => $name,
            'handle' => $handle,
            'instructions' => '',
            // @todo - test locales/sites behavior
            'translationMethod' => Field::TRANSLATION_METHOD_NONE,
        ]);

        // Set our field context
        Craft::$app->content->fieldContext = $form->getFieldContext();
        Craft::$app->content->contentTable = $form->getContentTable();

        $fieldsService->saveField($field);

        return $field;
    }

    /**
     * @param             $name
     * @param             $sortOrder
     * @param FormElement $form
     *
     * @return FieldLayoutTabRecord
     * @throws InvalidConfigException
     */
    public function createNewTab($name, $sortOrder, FormElement $form): FieldLayoutTabRecord
    {
        $fieldLayout = $form->getFieldLayout();

        $tabRecord = new FieldLayoutTabRecord();
        $tabRecord->name = $name;
        $tabRecord->sortOrder = $sortOrder;
        $tabRecord->layoutId = $fieldLayout->id;

        $tabRecord->save();

        return $tabRecord;
    }

    /**
     * Renames tab of form layout
     *
     * @param             $name
     * @param             $oldName
     * @param FormElement $form
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function renameTab($name, $oldName, FormElement $form): bool
    {
        $fieldLayout = $form->getFieldLayout();
        $tabs = $fieldLayout->getTabs();
        $response = false;

        foreach ($tabs as $tab) {
            if ($tab->name == $oldName) {
                $tabRecord = FieldLayoutTabRecord::findOne($tab->id);

                if ($tabRecord) {
                    $tabRecord->name = $name;
                    $response = $tabRecord->save(false);
                }
            }
        }

        return $response;
    }

    /**
     * Prepends a key/value pair to an array
     *
     * @param array  $haystack
     * @param string $key
     * @param mixed  $value
     *
     * @return array
     * @see array_unshift()
     *
     */
    public function prependKeyValue(array $haystack, $key, $value): array
    {
        $haystack = array_reverse($haystack, true);
        $haystack[$key] = $value;

        return array_reverse($haystack, true);
    }
}
