<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * parity, the parts the report test does not reach: each audit section run on
 * its own against iblocks and hand-written models made for the case.
 */
final class ParityAuditInternalsTest extends SyntheticTestCase
{
    /** Generated classes outlive a test: each test gets a namespace of its own. */
    private string $models;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $unique = 'ParityInternals'.bin2hex(random_bytes(4));
        $this->namespace = self::NAMESPACE.'\\'.$unique;
        $this->models = $this->workDir.'/models/'.$unique;
        mkdir($this->models);
        config([
            'atrium-bitrix.model_namespace' => $this->namespace,
            'atrium-bitrix.model_path' => $this->models,
            'atrium-bitrix.reference_namespace' => $this->namespace.'\\References',
            'atrium-bitrix.reference_path' => $this->models.'/References',
        ]);
    }

    public function test_a_property_without_a_code_is_placed_by_the_code_made_from_its_name(): void
    {
        $this->iblock(20, 'demo_warranty', 'content');
        $this->bitrix()->table('b_iblock_property')->insert([
            'ID' => 40, 'IBLOCK_ID' => 20, 'NAME' => 'Warranty', 'CODE' => null, 'PROPERTY_TYPE' => 'S', 'SORT' => 100, 'MULTIPLE' => 'N', 'ACTIVE' => 'Y',
        ]);
        $this->form(20, 'edit1--#--Main--,--NAME--#--Name--,--PROPERTY_40--#--Warranty--;--');
        $this->model('Warranty', [
            ['name' => 'name', 'type' => 'text'],
            ['name' => 'warranty', 'type' => 'text'],
        ]);

        $this->artisan('atrium:bitrix:parity', ['--iblock' => ['20']])
            ->expectsTable(['IBlock', 'Tabs in Bitrix', 'Tabs here', 'Field on the wrong tab', 'Order'], [
                ['demo_warranty', 'Main', '', '—', '2/2'],
            ])
            ->assertFailed();
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function iblock(int $id, ?string $code, string $type, array $extra = []): void
    {
        $this->bitrix()->table('b_iblock')->insert([
            'ID' => $id, 'IBLOCK_TYPE_ID' => $type, 'CODE' => $code, 'NAME' => 'Iblock '.$id, 'ACTIVE' => 'Y', 'SORT' => 500 + $id, 'VERSION' => 1, ...$extra,
        ]);
    }

    /** The form of an iblock as an editor arranged it. */
    private function form(int $iblockId, string $tabs): void
    {
        $this->bitrix()->table('b_user_option')->insert([
            'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_'.$iblockId, 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => $tabs]),
        ]);
    }

    /**
     * A model whose admin fields are plain arrays.
     *
     * @param  list<array<string,mixed>>  $fields
     */
    private function model(string $class, array $fields): void
    {
        $fields = var_export($fields, true);
        file_put_contents($this->models.'/'.$class.'.php', <<<PHP
            <?php

            namespace {$this->namespace};

            class {$class} extends \\Illuminate\\Database\\Eloquent\\Model
            {
                public static function getAdministrationFields(): array
                {
                    return {$fields};
                }
            }
            PHP);
    }
}
