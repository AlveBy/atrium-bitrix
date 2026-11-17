<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\GeneratedPaths;
use Atrium\Bitrix\Support\Labels;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\ProjectFields;
use Atrium\Bitrix\Support\SectionUserFields;
use Illuminate\Database\ConnectionInterface;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;

class SectionModelGenerator
{
    private NamingConvention $naming;

    private string $namespace;

    /** Bitrix connection, needed for section user fields (UF_*). */
    private ?ConnectionInterface $bitrix = null;

    public function __construct(NamingConvention $naming, ?ConnectionInterface $bitrix = null)
    {
        $this->naming = $naming;
        $this->bitrix = $bitrix;
        $this->namespace = config('atrium-bitrix.model_namespace', 'App\\Models');
    }

    /**
     * Generate Eloquent model for IBlock sections
     */
    public function generate(IBlock $iblock): string
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $className = $this->naming->toSectionModelName($iblock->code);
        $tableName = $this->naming->toSectionTableName($iblock->code);
        $userFields = $this->bitrix !== null
            ? SectionUserFields::load($this->bitrix, $iblock->id, $this->naming)
            : SectionUserFields::empty();
        $elementModelName = $this->naming->toModelName($iblock->code);

        $namespace = $file->addNamespace($this->namespace);
        $this->addUseStatements($namespace);

        $class = $namespace->addClass($className);
        $class->setExtends('Illuminate\\Database\\Eloquent\\Model');

        // Add interfaces
        $class->addImplement('Atrium\\Admin\\Contracts\\Administrable');

        // Add traits
        $class->addTrait('Atrium\\Admin\\Traits\\InteractsWithAdmin');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminSlug');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminSeo');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminTabs');

        // Table name (no type hint for PHP 8.4 compatibility with Laravel)
        $class->addProperty('table', $tableName)
            ->setProtected();

        // Fillable (no type hint for PHP 8.4 compatibility with Laravel)
        $class->addProperty('fillable', array_merge([
            'parent_id',
            'name',
            'code',
            'sort',
            'active',
            'description',
            'picture',
            'detail_picture',
            'depth',
            'left_margin',
            'right_margin',
        ], array_column($userFields->all(), 'column'), ProjectFields::fillableFor($tableName)))->setProtected();

        // Casts (no type hint for PHP 8.4 compatibility with Laravel)
        $class->addProperty('casts', array_merge([
            'active' => 'boolean',
            'sort' => 'integer',
            'depth' => 'integer',
            'left_margin' => 'integer',
            'right_margin' => 'integer',
        ], ProjectFields::castsFor($tableName)))->setProtected();

        // Parent relationship
        $class->addMethod('parent')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo')
            ->setBody("return \$this->belongsTo(self::class, 'parent_id');");

        // Children relationship
        $class->addMethod('children')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\HasMany')
            ->setBody("return \$this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');");

        // Elements relationship
        $class->addMethod('elements')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\HasMany')
            ->setBody("return \$this->hasMany({$elementModelName}::class, 'section_id');");

        // Ancestors accessor
        $class->addMethod('getAncestorsAttribute')
            ->setReturnType('Illuminate\\Support\\Collection')
            ->setBody(<<<'PHP'
$ancestors = collect();
$parent = $this->parent;

while ($parent) {
    $ancestors->prepend($parent);
    $parent = $parent->parent;
}

return $ancestors;
PHP);

        // Descendants accessor
        $class->addMethod('getDescendantsAttribute')
            ->setReturnType('Illuminate\\Support\\Collection')
            ->setBody(<<<'PHP'
return static::where('left_margin', '>', $this->left_margin)
    ->where('right_margin', '<', $this->right_margin)
    ->orderBy('left_margin')
    ->get();
PHP);

        // Scopes
        $scopeRoots = $class->addMethod('scopeRoots')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody("return \$query->whereNull('parent_id');");
        $scopeRoots->addParameter('query');

        $scopeActive = $class->addMethod('scopeActive')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody("return \$query->where('active', true);");
        $scopeActive->addParameter('query');

        $scopeSorted = $class->addMethod('scopeSorted')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody("return \$query->orderBy('left_margin');");
        $scopeSorted->addParameter('query');

        // Get tree method
        $class->addMethod('getTree')
            ->setStatic()
            ->setReturnType('Illuminate\\Support\\Collection')
            ->setBody(<<<'PHP'
return static::roots()
    ->with('children')
    ->sorted()
    ->get();
PHP);

        // URL path methods for HasAdminSlug
        $class->addMethod('getPathSlug')
            ->setReturnType('string')
            ->addComment('Get the full section path through all ancestors')
            ->setBody(<<<'PHP'
$parts = $this->ancestors->pluck('code')->toArray();
$parts[] = $this->code;

return implode('/', array_filter($parts));
PHP);

        $class->addMethod('getParentPath')
            ->setReturnType('string')
            ->addComment('Get the parent path (for HasAdminSlug)')
            ->setBody(<<<'PHP'
return $this->ancestors->pluck('code')->implode('/');
PHP);

        // Tab methods
        $class->addMethod('usesTabs')
            ->setStatic()
            ->setReturnType('bool')
            ->setBody('return true;');

        $class->addMethod('getAdminTabs')
            ->setStatic()
            ->setReturnType('array')
            ->setBody(<<<'PHP'
return [
    ['name' => 'params', 'label' => __('atrium::fields.tabs.main')],
    ['name' => 'content', 'label' => __('atrium::fields.tabs.description')],
    ['name' => 'seo', 'label' => __('atrium::fields.tabs.seo')],
];
PHP);

        // Admin methods
        $class->addMethod('getAdminLabel')
            ->setStatic()
            ->setReturnType('string')
            ->setBody('return '.Labels::literal($iblock->name.': ')." . __('atrium::fields.tabs.sections');");

        $class->addMethod('getAdministrationFields')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($this->buildSectionFields($tableName, $userFields));

        $class->addMethod('getAdministrationListColumns')
            ->setStatic()
            ->setReturnType('array')
            ->setBody(<<<'PHP'
return [
    TextColumn::make('id')->label('ID')->sortable(),
    TextColumn::make('name')->label(__('atrium::fields.standard.name'))->sortable()->searchable(),
    BooleanColumn::make('active')->label(__('atrium::fields.standard.active')),
    TextColumn::make('sort')->label(__('atrium::fields.standard.sort'))->sortable(),
];
PHP);

        $printer = new PsrPrinter;

        return $printer->printFile($file);
    }

    /**
     * Section form fields. Project columns (absent in Bitrix) are inserted
     * by their `after` key so regeneration neither drops nor shifts them.
     */
    private function buildSectionFields(string $tableName, SectionUserFields $userFields): string
    {
        $fields = [
            ['name' => 'parent_id', 'tab' => 'params', 'line' => "TreeSelectField::make('parent_id')->label(".Labels::standard('parent_section').')->model(self::class)'],
            ['name' => 'name', 'tab' => 'params', 'line' => "TextField::make('name')->label(".Labels::standard('name').')->required()'],
            ['name' => 'code', 'tab' => 'params', 'line' => "SlugField::make('code')->label(".Labels::standard('code').")->from('name')"],
            ['name' => 'active', 'tab' => 'params', 'line' => "CheckboxField::make('active')->label(".Labels::standard('active').')->default(true)'],
            ['name' => 'sort', 'tab' => 'params', 'line' => "NumberField::make('sort')->label(".Labels::standard('sort').')->default(500)'],
            ['name' => 'description', 'tab' => 'content', 'line' => "ContentField::make('description')->label(".Labels::standard('description').')'],
            ['name' => 'picture', 'tab' => 'content', 'line' => "ImageField::make('picture')->label(".Labels::standard('picture').')'],
            ['name' => 'detail_picture', 'tab' => 'content', 'line' => "ImageField::make('detail_picture')->label(".Labels::standard('detail_picture').')'],
        ];

        foreach ($userFields->all() as $uf) {
            $label = str_replace("'", "\\'", $uf['label']);

            if ($uf['enum'] !== []) {
                $options = [];
                foreach ($uf['enum'] as $option) {
                    $options[] = "['value' => '".$option['code']."', 'label' => '".str_replace("'", "\\'", $option['label'])."']";
                }
                $line = "SelectField::make('{$uf['column']}')->label('{$label}')->options([".implode(', ', $options).'])';
            } else {
                $line = "TextField::make('{$uf['column']}')->label('{$label}')";
            }

            $fields[] = ['name' => $uf['column'], 'tab' => 'params', 'line' => $line];
        }

        foreach (ProjectFields::adminFieldsFor($tableName) as $extra) {
            $at = count($fields);

            if ($extra['after'] !== null) {
                foreach ($fields as $i => $field) {
                    if ($field['name'] === $extra['after']) {
                        $at = $i + 1;
                        break;
                    }
                }
            }

            array_splice($fields, $at, 0, [$extra]);
        }

        $lines = ['return ['];
        $currentTab = null;

        foreach ($fields as $field) {
            if ($field['tab'] !== $currentTab) {
                if ($currentTab !== null) {
                    $lines[] = '';
                }
                $lines[] = '    // '.($field['tab'] === 'content' ? 'Description' : 'Parameters');
                $currentTab = $field['tab'];
            }

            $lines[] = "    {$field['line']}->tab('{$field['tab']}'),";
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Add use statements
     */
    private function addUseStatements(PhpNamespace $namespace): void
    {
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Model');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Builder');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\HasMany');
        $namespace->addUse('Illuminate\\Support\\Collection');
        $namespace->addUse('Atrium\\Admin\\Contracts\\Administrable');
        $namespace->addUse('Atrium\\Admin\\Traits\\InteractsWithAdmin');
        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminSlug');
        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminSeo');
        $namespace->addUse('Atrium\\Admin\\Fields\\TextField');
        $namespace->addUse('Atrium\\Admin\\Fields\\ContentField');
        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminTabs');
        $namespace->addUse('Atrium\\Admin\\Fields\\NumberField');
        $namespace->addUse('Atrium\\Admin\\Fields\\CheckboxField');
        $namespace->addUse('Atrium\\Admin\\Fields\\ImageField');
        $namespace->addUse('Atrium\\Admin\\Fields\\SlugField');
        $namespace->addUse('Atrium\\Admin\\Fields\\TreeSelectField');
        // Section user fields can be lists (e.g. a view-type enum), and without
        // this import the model failed with
        // "Class App\Models\SelectField not found".
        $namespace->addUse('Atrium\\Admin\\Fields\\SelectField');
        $namespace->addUse('Atrium\\Admin\\Fields\\TextareaField');
        $namespace->addUse('Atrium\\Admin\\Columns\\TextColumn');
        $namespace->addUse('Atrium\\Admin\\Columns\\BooleanColumn');
    }

    /**
     * Get the generated file path
     */
    public function getFilePath(IBlock $iblock): string
    {
        $className = $this->naming->toSectionModelName($iblock->code);

        return GeneratedPaths::directory('model_path', $this->namespace).'/'.$className.'.php';
    }
}
