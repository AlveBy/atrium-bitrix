<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\BitrixFormLayout;
use Atrium\Bitrix\Support\BitrixIblockMessages;
use Atrium\Bitrix\Support\BitrixListLayout;
use Atrium\Bitrix\Support\GeneratedPaths;
use Atrium\Bitrix\Support\Labels;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\ProjectFields;
use Atrium\Bitrix\Support\ReservedColumns;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;

class ModelGenerator
{
    protected NamingConvention $naming;

    protected PropertyTypeMapper $typeMapper;

    protected FieldTypeMapper $fieldMapper;

    protected string $namespace;

    /**
     * Bitrix connection, needed to read the saved form layout.
     * Without it the generator falls back to name-based heuristics.
     */
    protected ?ConnectionInterface $bitrix;

    /** Form layout of the current iblock; filled in generate(). */
    protected ?BitrixFormLayout $layout = null;

    /** Tabs that actually ended up holding fields; filled in generateFieldsMethod(). */
    protected array $usedTabs = [];

    /** List layout of the current iblock; filled in generate(). */
    protected ?BitrixListLayout $listLayout = null;

    /** Captions of the current iblock (element name, "add element" button); filled in generate(). */
    protected ?BitrixIblockMessages $messages = null;

    public function __construct(
        NamingConvention $naming,
        PropertyTypeMapper $typeMapper,
        FieldTypeMapper $fieldMapper,
        ?ConnectionInterface $bitrix = null
    ) {
        $this->naming = $naming;
        $this->typeMapper = $typeMapper;
        $this->fieldMapper = $fieldMapper;
        $this->bitrix = $bitrix;
        $this->namespace = config('atrium-bitrix.model_namespace', 'App\\Models');
    }

    /**
     * List columns as configured in the Bitrix admin.
     */
    protected function loadListLayout(IBlock $iblock): ?BitrixListLayout
    {
        if ($this->bitrix === null) {
            return null;
        }

        $codes = [];
        foreach ($iblock->properties as $property) {
            $codes[$property->id] = $property->code;
        }

        return BitrixListLayout::load($this->bitrix, $iblock->iblockType, $iblock->id, $codes, $this->naming);
    }

    /**
     * Iblock captions: what the element is called and the label of its "add" button.
     */
    protected function loadMessages(IBlock $iblock): ?BitrixIblockMessages
    {
        if ($this->bitrix === null) {
            return null;
        }

        return BitrixIblockMessages::load($this->bitrix, $iblock->id);
    }

    /**
     * Form layout from the Bitrix admin: field order and tab names.
     * Editors compare the new admin with the old one line by line, so the
     * form is built from the saved layout rather than guessed from field names.
     */
    protected function loadLayout(IBlock $iblock): ?BitrixFormLayout
    {
        if ($this->bitrix === null) {
            return null;
        }

        $codes = [];
        foreach ($iblock->properties as $property) {
            $codes[$property->id] = $property->code;
        }

        return BitrixFormLayout::load($this->bitrix, $iblock->id, $codes, $this->naming);
    }

    /**
     * Generate Eloquent model for IBlock
     */
    public function generate(IBlock $iblock): string
    {
        $this->layout = $this->loadLayout($iblock);
        $this->listLayout = $this->loadListLayout($iblock);
        $this->messages = $this->loadMessages($iblock);

        $file = new PhpFile;
        $file->setStrictTypes();

        $className = $this->naming->toModelName($iblock->code);
        $tableName = $this->naming->toTableName($iblock->code);

        $namespace = $file->addNamespace($this->namespace);
        $this->addUseStatements($namespace, $iblock);

        $class = $namespace->addClass($className);
        $class->setExtends('Illuminate\\Database\\Eloquent\\Model');

        // Add interfaces
        $this->addInterfaces($class, $iblock);

        // Add traits
        $this->addTraits($class, $iblock);

        // Add URL path for slug generation
        $urlPath = $this->naming->toUrlPath($iblock->code);
        $class->addProperty('urlPath', $urlPath)
            ->setProtected()
            ->setStatic()
            ->setType('string')
            ->addComment('Base path for URLs (as in Bitrix IBlock)');

        // Add table name (no type hint for PHP 8.4 compatibility with Laravel)
        $class->addProperty('table', $tableName)
            ->setProtected();

        // Add fillable (no type hint for PHP 8.4 compatibility with Laravel)
        $fillable = $this->getFillableProperties($iblock);
        $class->addProperty('fillable', $fillable)
            ->setProtected();

        // Add casts (no type hint for PHP 8.4 compatibility with Laravel)
        $casts = $this->getCasts($iblock);
        if (! empty($casts)) {
            $class->addProperty('casts', $casts)
                ->setProtected();
        }

        // Add relationships
        $this->addRelationships($class, $iblock);

        // Add Administrable implementation
        $this->addAdminMethods($class, $iblock);

        // Add scopes
        $this->addScopes($class, $iblock);

        $printer = new PsrPrinter;

        return $printer->printFile($file);
    }

    /**
     * Add use statements
     */
    protected function addUseStatements(PhpNamespace $namespace, IBlock $iblock): void
    {
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Model');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\HasMany');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Builder');

        // Add trait uses
        if ($iblock->hasFileProperties()) {
            $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminFiles');
        }

        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminSlug');
        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminSeo');
        $namespace->addUse('Atrium\\Admin\\Traits\\HasAdminTabs');
        $namespace->addUse('Atrium\\Admin\\Traits\\InteractsWithAdmin');
        $namespace->addUse('Atrium\\Admin\\Contracts\\Administrable');

        // Add field classes for admin
        $namespace->addUse('Atrium\\Admin\\Fields\\TextField');
        $namespace->addUse('Atrium\\Admin\\Fields\\TextareaField');
        $namespace->addUse('Atrium\\Admin\\Fields\\WysiwygField');
        $namespace->addUse('Atrium\\Admin\\Fields\\ContentField');
        $namespace->addUse('Atrium\\Admin\\Fields\\CheckboxField');
        $namespace->addUse('Atrium\\Admin\\Fields\\SelectField');
        $namespace->addUse('Atrium\\Admin\\Fields\\MultiSelectField');
        $namespace->addUse('Atrium\\Admin\\Fields\\ImageField');
        $namespace->addUse('Atrium\\Admin\\Fields\\GalleryField');
        $namespace->addUse('Atrium\\Admin\\Fields\\SlugField');
        $namespace->addUse('Atrium\\Admin\\Fields\\NumberField');
        $namespace->addUse('Atrium\\Admin\\Fields\\DateField');
        $namespace->addUse('Atrium\\Admin\\Fields\\DateTimeField');
        $namespace->addUse('Atrium\\Admin\\Fields\\TreeSelectField');
        $namespace->addUse('Atrium\\Admin\\Fields\\BelongsToField');
        $namespace->addUse('Atrium\\Admin\\Fields\\BelongsToManyField');
        $namespace->addUse('Atrium\\Admin\\Fields\\MapField');
        $namespace->addUse('Atrium\\Admin\\Fields\\RepeatableField');
        $namespace->addUse('Atrium\\Admin\\Columns\\TextColumn');
        $namespace->addUse('Atrium\\Admin\\Columns\\ImageColumn');
        $namespace->addUse('Atrium\\Admin\\Columns\\BooleanColumn');
        $namespace->addUse('Atrium\\Admin\\Columns\\DateColumn');
    }

    /**
     * Add interfaces
     */
    protected function addInterfaces(ClassType $class, IBlock $iblock): void
    {
        $class->addImplement('Atrium\\Admin\\Contracts\\Administrable');
    }

    /**
     * Add traits
     */
    protected function addTraits(ClassType $class, IBlock $iblock): void
    {
        $class->addTrait('Atrium\\Admin\\Traits\\InteractsWithAdmin');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminSlug');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminSeo');
        $class->addTrait('Atrium\\Admin\\Traits\\HasAdminTabs');

        if ($iblock->hasFileProperties()) {
            $class->addTrait('Atrium\\Admin\\Traits\\HasAdminFiles');
        }
    }

    /**
     * Get fillable properties
     */
    protected function getFillableProperties(IBlock $iblock): array
    {
        $fillable = [
            'name',
            'code',
            'sort',
            'active',
            'active_from',
            'active_to',
            'preview_text',
            'preview_picture',
            'detail_text',
            'detail_picture',
        ];

        // Add section if has sections
        if (! empty($iblock->sections)) {
            $fillable[] = 'section_id';
        }

        // Add properties
        foreach ($iblock->properties as $property) {
            if (! $this->typeMapper->isFillable($property)) {
                continue;
            }

            $columnName = $this->naming->toColumnName($property->code);

            // A property whose CODE collides with a base field (e.g. CODE=NAME on
            // the contact forms) has no column of its own — the migration skips
            // it. Emitting it again would duplicate the base entry.
            if (ReservedColumns::has($columnName)) {
                continue;
            }

            // Multi-value directory properties live in a pivot table and are
            // rendered as BelongsToManyField, which names itself with the camel
            // plural (see generateFieldLine). $fillable has to use that exact
            // name: AdminController::store() reads submitted relation values out
            // of $request->only($fillable) before fill(), so a mismatch (PROFIT →
            // fillable 'profit' vs field 'profits') means the pivot never syncs.
            $fillable[] = $property->needsPivotTable()
                ? Str::camel(Str::plural($columnName))
                : $columnName;
        }

        // Columns added by the project through separate migrations (see config):
        // Bitrix knows nothing about them, but they must survive regeneration.
        return array_merge($fillable, ProjectFields::fillableFor($this->naming->toTableName($iblock->code)));
    }

    /**
     * Get casts array
     */
    protected function getCasts(IBlock $iblock): array
    {
        $casts = [
            'active' => 'boolean',
            'active_from' => 'datetime',
            'active_to' => 'datetime',
            'sort' => 'integer',
        ];

        foreach ($iblock->properties as $property) {
            $cast = $this->typeMapper->toCast($property);
            if ($cast) {
                $columnName = $this->naming->toColumnName($property->code);

                // No column was generated for a reserved-name property, so casting
                // it would silently re-type the base field instead.
                if (ReservedColumns::has($columnName)) {
                    continue;
                }

                $casts[$columnName] = $cast;
            }
        }

        return array_merge($casts, ProjectFields::castsFor($this->naming->toTableName($iblock->code)));
    }

    /**
     * Add relationships
     */
    protected function addRelationships(ClassType $class, IBlock $iblock): void
    {
        $mainTable = $this->naming->toTableName($iblock->code);

        // Section relationship
        if (! empty($iblock->sections)) {
            $sectionModelName = $this->naming->toSectionModelName($iblock->code);

            $method = $class->addMethod('section')
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo')
                ->setBody("return \$this->belongsTo({$sectionModelName}::class);");
        }

        // Property relationships
        foreach ($iblock->properties as $property) {
            if ($property->isElementLink()) {
                $this->addElementLinkRelation($class, $property, $mainTable);
            } elseif ($property->isSectionLink()) {
                $this->addSectionLinkRelation($class, $property, $mainTable);
            } elseif ($property->isDirectory()) {
                $this->addDirectoryRelation($class, $property, $mainTable);
            }
        }
    }

    /**
     * Add element link relationship
     */
    protected function addElementLinkRelation(ClassType $class, IBlockProperty $property, string $mainTable): void
    {
        $relationName = $this->naming->toRelationName($property->code, $property->multiple);
        $columnName = $this->naming->toColumnName($property->code);

        // Determine related model
        $relatedModel = $property->linkIblockCode
            ? $this->naming->toModelName($property->linkIblockCode)
            : 'self';

        if ($property->multiple) {
            // Pivot table: {main_table}_{column_name} - same as ElementMigrationGenerator
            $pivotTable = "{$mainTable}_{$columnName}";

            // Foreign keys - same formula as ElementMigrationGenerator
            $foreignKey = Str::singular($mainTable).'_id';
            $relatedKey = Str::singular($columnName).'_id';

            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany')
                ->setBody("return \$this->belongsToMany({$relatedModel}::class, '{$pivotTable}', '{$foreignKey}', '{$relatedKey}')\n    ->withPivot('sort')\n    ->orderBy('pivot_sort');");
        } else {
            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo')
                ->setBody("return \$this->belongsTo({$relatedModel}::class, '{$columnName}');");
        }
    }

    /**
     * Add section link relationship
     */
    protected function addSectionLinkRelation(ClassType $class, IBlockProperty $property, string $mainTable): void
    {
        $relationName = $this->naming->toRelationName($property->code, $property->multiple);
        $columnName = $this->naming->toColumnName($property->code);

        // Without a resolvable target there is no valid class to point at.
        // Emitting a placeholder produced `belongsTo(Section::class)` — a class
        // that does not exist and fatals the first time the relation is used.
        if (! $property->linkIblockCode) {
            return;
        }

        $sectionModel = $this->naming->toSectionModelName($property->linkIblockCode);

        if ($property->multiple) {
            $pivotTable = "{$mainTable}_{$columnName}";
            $foreignKey = Str::singular($mainTable).'_id';

            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany')
                ->setBody("return \$this->belongsToMany({$sectionModel}::class, '{$pivotTable}', '{$foreignKey}', 'section_id');");
        } else {
            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo')
                ->setBody("return \$this->belongsTo({$sectionModel}::class, '{$columnName}');");
        }
    }

    /**
     * Add directory (HL block) relationship
     */
    protected function addDirectoryRelation(ClassType $class, IBlockProperty $property, string $mainTable): void
    {
        $relationName = $this->naming->toRelationName($property->code, $property->multiple);
        $columnName = $this->naming->toColumnName($property->code);

        // Determine related model from HL block
        if ($property->directoryHlblock) {
            $relatedModel = $this->naming->hlblockToModelName($property->directoryHlblock->name);
        } elseif ($property->directoryTableName) {
            // Fallback: derive model name from table name
            $relatedModel = Str::studly($property->directoryTableName);
        } else {
            $relatedModel = 'Reference';
        }

        $referenceNamespace = config('atrium-bitrix.reference_namespace', 'App\\Models\\References');

        if ($property->multiple) {
            $pivotTable = "{$mainTable}_{$columnName}";
            $foreignKey = Str::singular($mainTable).'_id';
            $relatedKey = Str::singular($columnName).'_id';

            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany')
                ->setBody("return \$this->belongsToMany(\\{$referenceNamespace}\\{$relatedModel}::class, '{$pivotTable}', '{$foreignKey}', '{$relatedKey}')\n    ->withPivot('sort')\n    ->orderBy('pivot_sort');");
        } else {
            $class->addMethod($relationName)
                ->setReturnType('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo')
                ->setBody("return \$this->belongsTo(\\{$referenceNamespace}\\{$relatedModel}::class, '{$columnName}');");
        }
    }

    /**
     * Add admin panel methods
     */
    protected function addAdminMethods(ClassType $class, IBlock $iblock): void
    {
        // getAdminLabel
        $class->addMethod('getAdminLabel')
            ->setStatic()
            ->setReturnType('string')
            ->setBody("return '{$iblock->name}';");

        // The "add element" button on the public panel: take the label from the
        // iblock itself, where a human already wrote it in the right grammatical form.
        $createLabel = $this->messages?->elementAdd();

        if ($createLabel !== null) {
            $class->addMethod('getAdminCreateLabel')
                ->setStatic()
                ->setReturnType('string')
                ->setBody('return '.var_export($createLabel, true).';');
        }

        // usesTabs - enable tabs for admin form
        $class->addMethod('usesTabs')
            ->setStatic()
            ->setReturnType('bool')
            ->setBody('return true;');

        // Build the fields first: getAdminTabs() must know which tabs are
        // actually occupied, otherwise a field lands on a tab missing from the
        // list and is simply not rendered (the Preview and Detail tabs used to
        // vanish this way for iblocks whose Bitrix form has no such tabs).
        $fieldsBody = $this->generateFieldsMethod($iblock);

        // getAdminTabs - define available tabs
        $tabsBody = $this->generateTabsMethod($iblock);
        $class->addMethod('getAdminTabs')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($tabsBody);

        $class->addMethod('getAdministrationFields')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($fieldsBody);

        // getAdministrationListColumns
        $columnsBody = $this->generateColumnsMethod($iblock);
        $class->addMethod('getAdministrationListColumns')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($columnsBody);
    }

    /**
     * Generate tabs method body
     */
    protected function generateTabsMethod(IBlock $iblock): string
    {
        // If there is a Bitrix layout, take its tabs as they are: the set,
        // the captions (the element's own name rather than a generic "Params")
        // and the Sections tab, which used to be missing altogether.
        $tabs = array_map(
            static fn (array $tab): array => ['name' => $tab['name'], 'label' => Labels::literal($tab['label'])],
            $this->layout?->tabs() ?? []
        );

        if ($tabs === []) {
            $tabs = array_map(
                static fn (string $name): array => ['name' => $name, 'label' => Labels::tab($name)],
                ['params', 'preview', 'detail', 'seo']
            );
        }

        // A tab that holds fields must be in the list, otherwise they are not
        // rendered. Add the missing ones in canonical order.
        $defaults = ['params', 'preview', 'detail', 'seo', 'sections'];
        $present = array_column($tabs, 'name');

        foreach ($defaults as $name) {
            if (in_array($name, $this->usedTabs, true) && ! in_array($name, $present, true)) {
                $tabs[] = ['name' => $name, 'label' => Labels::tab($name)];
            }
        }

        // Canonical tab order, so that added tabs do not end up at the tail.
        $order = array_flip($defaults);
        usort($tabs, static fn (array $a, array $b): int => ($order[$a['name']] ?? 99) <=> ($order[$b['name']] ?? 99));

        $lines = ['return ['];

        foreach ($tabs as $tab) {
            $lines[] = "    ['name' => '{$tab['name']}', 'label' => {$tab['label']}],";
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Generate fields method body
     */
    protected function generateFieldsMethod(IBlock $iblock): string
    {
        // A Bitrix property with CODE=NAME is a distinct business field (the
        // submitter's full name on the contact forms), but it shares the base column,
        // so its label is carried onto the base field instead of emitting a
        // second TextField::make('name') and duplicating the DOM id.
        $nameLabel = $this->nameLabel($iblock);

        /** @var array<int,array{key:string,line:string,tab:string}> $entries */
        $entries = [];

        $add = function (string $key, string $line, string $defaultTab) use (&$entries): void {
            $entries[] = [
                'key' => $key,
                'line' => $line,
                'tab' => $this->resolveTab($key, $defaultTab),
            ];
        };

        // Base fields follow the default Bitrix form order
        // (Active -> period -> Name -> Code -> Sort). Iblocks with a saved layout
        // get it overridden anyway, but the others have none, and they used to
        // diverge from the old admin for no reason.
        $add('active', "CheckboxField::make('active')->label(".Labels::standard('active').')->default(true)', 'params');
        $add('active_from', "DateTimeField::make('active_from')->label(".Labels::standard('active_from').')', 'params');
        $add('active_to', "DateTimeField::make('active_to')->label(".Labels::standard('active_to').')', 'params');
        $add('name', "TextField::make('name')->label({$nameLabel})->required()", 'params');
        $add('code', "SlugField::make('code')->label(".Labels::standard('code').")->from('name')", 'params');
        $add('sort', "NumberField::make('sort')->label(".Labels::standard('sort').')->default(500)', 'params');

        if (! empty($iblock->sections)) {
            $sectionModel = $this->naming->toSectionModelName($iblock->code);
            $add('section_id', "TreeSelectField::make('section_id')->label(".Labels::standard('section').")->model({$sectionModel}::class)", 'params');
        }

        $add('preview_picture', "ImageField::make('preview_picture')->label(".Labels::standard('preview_picture').')', 'preview');
        $add('preview_text', "ContentField::make('preview_text')->label(".Labels::standard('preview_text').')', 'preview');
        $add('detail_picture', "ImageField::make('detail_picture')->label(".Labels::standard('detail_picture').')', 'detail');
        $add('detail_text', "ContentField::make('detail_text')->label(".Labels::standard('detail_text').')', 'detail');

        foreach ($iblock->properties as $property) {
            $column = $this->naming->toColumnName($property->code);

            // Reserved-name properties share the base column and were already
            // folded into the base field above.
            if (ReservedColumns::has($column)) {
                continue;
            }

            $fieldConfig = $this->fieldMapper->toAdminField($property);
            $fieldLine = $this->generateFieldLine($fieldConfig);

            if ($fieldLine === '') {
                continue;
            }

            // The layout key is the property code: the Bitrix form stores the
            // field as PROPERTY_{ID}, while our widget name may differ
            // (BelongsToManyField moves to the relation name: link_goods -> linkGoods).
            $add($column, $fieldLine, $this->determineFieldTab($fieldConfig['name']));
        }

        // Order follows the Bitrix form. Fields missing from its layout
        // (added after the form was last configured) go to the end of their
        // tab instead of getting mixed in with the rest.
        if ($this->layout !== null) {
            $tabOrder = array_flip(array_column($this->layout->tabs(), 'name'));

            usort($entries, function (array $a, array $b) use ($tabOrder): int {
                return [$tabOrder[$a['tab']] ?? PHP_INT_MAX, $this->layout->positionOf($a['key'])]
                    <=> [$tabOrder[$b['tab']] ?? PHP_INT_MAX, $this->layout->positionOf($b['key'])];
            });
        }

        // Project fields are inserted AFTER sorting by the Bitrix layout:
        // it does not know them, and their position is set by the `after` key.
        foreach (ProjectFields::adminFieldsFor($this->naming->toTableName($iblock->code)) as $extra) {
            $at = count($entries);

            if ($extra['after'] !== null) {
                foreach ($entries as $i => $entry) {
                    if ($entry['key'] === $extra['after']) {
                        $at = $i + 1;
                        break;
                    }
                }
            }

            array_splice($entries, $at, 0, [[
                'key' => $extra['name'],
                'line' => $extra['line'],
                'tab' => $extra['tab'],
            ]]);
        }

        $this->usedTabs = array_values(array_unique(array_column($entries, 'tab')));

        $lines = ['return ['];
        $currentTab = null;

        foreach ($entries as $entry) {
            if ($entry['tab'] !== $currentTab) {
                if ($currentTab !== null) {
                    $lines[] = '';
                }
                $lines[] = '    // '.$this->tabLabel($entry['tab']);
                $currentTab = $entry['tab'];
            }

            $lines[] = "    {$entry['line']}->tab('{$entry['tab']}'),";
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Tab of a field: from the Bitrix layout, otherwise by a name heuristic.
     *
     * If the field is not in the layout and its "natural" tab does not exist in
     * this form (some iblocks have no Preview/Detail tabs), put it on the first
     * tab. Otherwise the field would land on a tab Bitrix never had: either we
     * invent an extra tab or the field disappears, and such iblocks may hold
     * real content there.
     */
    protected function resolveTab(string $key, string $defaultTab): string
    {
        $fromLayout = $this->layout?->tabFor($key);

        if ($fromLayout !== null) {
            return $fromLayout;
        }

        if ($this->layout !== null && ! $this->layout->hasTab($defaultTab)) {
            return $this->layout->tabs()[0]['name'] ?? $defaultTab;
        }

        return $defaultTab;
    }

    /**
     * Tab caption for the comment in the generated code.
     */
    protected function tabLabel(string $name): string
    {
        foreach ($this->layout?->tabs() ?? [] as $tab) {
            if ($tab['name'] === $name) {
                return $tab['label'];
            }
        }

        return match ($name) {
            'preview' => 'Preview',
            'detail' => 'Details',
            'seo' => 'SEO',
            'sections' => 'Sections',
            default => 'Parameters',
        };
    }

    /**
     * List columns according to the Bitrix admin settings.
     */
    protected function generateColumnsFromLayout(IBlock $iblock, string $nameLabel): string
    {
        // Properties by column name, to take the label and type from the property itself.
        $properties = [];
        foreach ($iblock->properties as $property) {
            $properties[$this->naming->toColumnName($property->code)] = $property;
        }

        $layout = [];
        foreach ($this->listLayout->columns() as $column) {
            $layout[$column['name']] = $column;
        }

        $table = $this->naming->toTableName($iblock->code);
        $names = ProjectFields::applyListOverrides(array_keys($layout), $table);

        $lines = ['return ['];

        foreach ($names as $name) {
            $projectColumn = ProjectFields::listColumnLineFor($table, $name);

            $lines[] = $projectColumn !== null
                ? '    '.$projectColumn.','
                : $this->columnLine(
                    $name,
                    $properties[$name] ?? null,
                    $nameLabel,
                    $layout[$name]['label'] ?? null
                );
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * One list column line.
     *
     * Label and type come from the iblock property when the column is a
     * property; for base fields (active, updated_at...) Bitrix stores no
     * label, so the decision is made by name.
     */
    protected function columnLine(
        string $name,
        ?IBlockProperty $property,
        string $nameLabel,
        ?string $layoutLabel = null
    ): string {
        if ($property !== null) {
            $config = $this->fieldMapper->toListColumn($property);
            $class = $config['class'];
            $label = Labels::literal((string) $config['label']);
            $sortable = $config['sortable'];
        } else {
            $class = match ($name) {
                'active' => 'BooleanColumn',
                'preview_picture', 'detail_picture' => 'ImageColumn',
                'created_at', 'updated_at', 'active_from', 'active_to' => 'DateColumn',
                default => 'TextColumn',
            };
            $standard = ['id', 'active', 'active_from', 'active_to', 'code', 'sort', 'created_at', 'updated_at', 'preview_picture', 'detail_picture', 'preview_text'];
            $label = match (true) {
                $name === 'name' => $nameLabel,
                $layoutLabel !== null => Labels::literal($layoutLabel),
                in_array($name, $standard, true) => Labels::standard($name),
                default => Labels::literal($name),
            };
            // Sorting by an image is pointless; the other base columns are fine.
            $sortable = ! in_array($name, ['preview_picture', 'detail_picture'], true);
        }

        $line = "    {$class}::make('{$name}')->label({$label})";

        if ($sortable) {
            $line .= '->sortable()';
        }

        // Searching makes sense by name only, which is how Bitrix search behaves.
        if ($name === 'name') {
            $line .= '->searchable()';
        }

        return $line.',';
    }

    /**
     * Generate single field line
     */
    protected function generateFieldLine(array $config): string
    {
        $class = $config['class'];
        $name = $config['name'];
        $label = $config['label'];

        // For BelongsToManyField, use plural relation name to match the relationship method
        if ($class === 'BelongsToManyField') {
            $name = Str::camel(Str::plural($name));
        }

        $line = "{$class}::make('{$name}')->label(".Labels::literal((string) $label).')';

        // Add model() for TreeSelectField, BelongsToField, BelongsToManyField
        if (! empty($config['model']) && in_array($class, ['TreeSelectField', 'BelongsToField', 'BelongsToManyField'])) {
            $line .= "->model({$config['model']}::class)";
        }

        if ($class === 'CheckboxField' && ! empty($config['checkedValue'])) {
            $line .= "->checkedValue('{$config['checkedValue']}')";
        }

        // Add itemType() for RepeatableField
        if ($class === 'RepeatableField' && ! empty($config['itemType'])) {
            $line .= "->itemType('{$config['itemType']}')";
        }

        if (! empty($config['required'])) {
            $line .= '->required()';
        }

        // Add options for SelectField/MultiSelectField only (not CheckboxField)
        if (! empty($config['options']) && in_array($class, ['SelectField', 'MultiSelectField'])) {
            $optionsStr = var_export($config['options'], true);
            $optionsStr = str_replace(["\n", '  '], ['', ''], $optionsStr);
            $line .= "->options({$optionsStr})";
        }

        if (! empty($config['hint'])) {
            $line .= "->hint('{$config['hint']}')";
        }

        // ->tab() is appended by generateFieldsMethod(): the tab comes from the
        // Bitrix form layout, and the field name alone is not enough for that.
        return $line;
    }

    /**
     * Determine which tab a field belongs to based on its name
     */
    protected function determineFieldTab(string $fieldName): string
    {
        $previewFields = ['preview_text', 'preview_picture'];
        $detailFields = ['detail_text', 'detail_picture', 'photos', 'documents', 'big_photos', 'gallery', 'planirovka'];
        $seoFields = ['meta_title', 'meta_description', 'meta_keywords', 'seo_title', 'seo_description'];

        if (in_array($fieldName, $previewFields) || str_starts_with($fieldName, 'preview_')) {
            return 'preview';
        }
        if (in_array($fieldName, $detailFields) || str_starts_with($fieldName, 'detail_')) {
            return 'detail';
        }
        if (in_array($fieldName, $seoFields) || str_starts_with($fieldName, 'meta_') || str_starts_with($fieldName, 'seo_')) {
            return 'seo';
        }

        return 'params';
    }

    /**
     * Label of the Bitrix property whose CODE collides with a reserved base column.
     *
     * Such a property gets no column of its own, so its business meaning would be
     * lost entirely; instead the base field adopts its Bitrix label (e.g. 'name'
     * becomes "Your name" on the order forms rather than the generic "Name").
     */
    protected function nameLabel(IBlock $iblock): string
    {
        $label = $this->getReservedPropertyLabel($iblock, 'name');

        return $label !== null ? Labels::literal($label) : Labels::standard('name');
    }

    protected function getReservedPropertyLabel(IBlock $iblock, string $column): ?string
    {
        foreach ($iblock->properties as $property) {
            if ($this->naming->toColumnName($property->code) !== $column) {
                continue;
            }

            $label = trim((string) $property->name);

            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    /**
     * Generate columns method body
     */
    protected function generateColumnsMethod(IBlock $iblock): string
    {
        $lines = ['return ['];

        $nameLabel = $this->nameLabel($iblock);

        // Take the set and order of columns from the Bitrix list settings if
        // saved. Without it the generator showed the first three properties,
        // which rarely are the columns editors actually need.
        if ($this->listLayout !== null && ! $this->listLayout->isEmpty()) {
            return $this->generateColumnsFromLayout($iblock, $nameLabel);
        }

        // No layout in Bitrix: show the base fields and a few of the first
        // properties, so the list does not consist of service columns only.
        $names = ['id', 'name', 'active'];
        $properties = [];

        foreach ($iblock->properties as $property) {
            $column = $this->naming->toColumnName($property->code);
            $properties[$column] = $property;

            // Reserved-name properties share a base column, already listed above.
            if (ReservedColumns::has($column) || in_array($column, $names, true)) {
                continue;
            }

            if (count($names) < 6) {
                $names[] = $column;
            }
        }

        $table = $this->naming->toTableName($iblock->code);
        $names = ProjectFields::applyListOverrides($names, $table);

        foreach ($names as $name) {
            $projectColumn = ProjectFields::listColumnLineFor($table, $name);

            $lines[] = $projectColumn !== null
                ? '    '.$projectColumn.','
                : $this->columnLine($name, $properties[$name] ?? null, $nameLabel);
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Add scopes
     */
    protected function addScopes(ClassType $class, IBlock $iblock): void
    {
        // Active scope
        $scopeActive = $class->addMethod('scopeActive')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody("return \$query->where('active', true);");
        $scopeActive->addParameter('query');

        // Sorted scope
        $scopeSorted = $class->addMethod('scopeSorted')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody("return \$query->orderBy('sort')->orderBy('name')->orderBy('id');");
        $scopeSorted->addParameter('query');

        // Active-period scope, the analogue of Bitrix CHECK_DATES='Y' (activity window, NULL = unbounded)
        $scopeActivePeriod = $class->addMethod('scopeActivePeriod')
            ->setReturnType('Illuminate\\Database\\Eloquent\\Builder')
            ->setBody(<<<'PHP'
                return $query
                    ->where(fn ($q) => $q->whereNull('active_from')->orWhere('active_from', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('active_to')->orWhere('active_to', '>=', now()));
                PHP);
        $scopeActivePeriod->addParameter('query');

        // getParentPath for URL generation (used by HasAdminSlug)
        if (! empty($iblock->sections)) {
            $sectionModelName = $this->naming->toSectionModelName($iblock->code);
            $class->addMethod('getParentPath')
                ->setReturnType('string')
                ->addComment('Get the parent path used to build the full URL')
                ->setBody(<<<'PHP'
$parts = [static::$urlPath];

if ($this->section) {
    $sectionPath = $this->section->getPathSlug();
    if ($sectionPath) {
        $parts[] = $sectionPath;
    }
}

return implode('/', $parts);
PHP);
        } else {
            // For models without sections, just return urlPath
            $class->addMethod('getParentPath')
                ->setReturnType('string')
                ->addComment('Get the parent path used to build the full URL')
                ->setBody('return static::$urlPath;');
        }
    }

    /**
     * Get the generated file path
     */
    public function getFilePath(IBlock $iblock): string
    {
        $className = $this->naming->toModelName($iblock->code);

        return GeneratedPaths::directory('model_path', $this->namespace).'/'.$className.'.php';
    }
}
