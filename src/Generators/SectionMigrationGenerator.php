<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\SectionUserFields;
use Illuminate\Database\ConnectionInterface;

class SectionMigrationGenerator
{
    protected NamingConvention $naming;

    /** Bitrix connection, needed for section user fields (UF_*). */
    protected ?ConnectionInterface $bitrix;

    public function __construct(NamingConvention $naming, ?ConnectionInterface $bitrix = null)
    {
        $this->naming = $naming;
        $this->bitrix = $bitrix;
    }

    /**
     * Generate section table migration
     */
    public function generate(IBlock $iblock): string
    {
        $tableName = $this->naming->toSectionTableName($iblock->code);

        // Section user fields: in Bitrix these are b_user_field rows with
        // ENTITY_ID = IBLOCK_{id}_SECTION. Without them such values were lost:
        // there simply were no columns for them.
        $userFields = $this->bitrix !== null
            ? SectionUserFields::load($this->bitrix, $iblock->id, $this->naming)
            : SectionUserFields::empty();

        $userColumns = '';
        foreach ($userFields->all() as $uf) {
            $type = $uf['enum'] !== [] ? "string('{$uf['column']}', 50)" : "text('{$uf['column']}')";
            $userColumns .= "\n            \$table->{$type}->nullable(); // {$uf['name']}";
        }

        if ($userColumns !== '') {
            $userColumns = "\n\n            // Section user fields".$userColumns;
        }

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->unsignedBigInteger('bitrix_id')->nullable()->index();
            \$table->foreignId('parent_id')->nullable()->constrained('{$tableName}')->nullOnDelete();

            // Base fields
            \$table->string('name');
            \$table->string('code')->nullable()->index();
            \$table->integer('sort')->default(500);
            \$table->boolean('active')->default(true)->index();

            // Content
            \$table->longText('description')->nullable();
            \$table->string('picture')->nullable();
            \$table->string('detail_picture')->nullable();{$userColumns}

            // Nested Set columns for tree structure
            \$table->integer('depth')->default(0);
            \$table->integer('left_margin')->default(0);
            \$table->integer('right_margin')->default(0);

            \$table->timestamps();
            \$table->softDeletes();

            // Indexes for tree operations
            \$table->index(['left_margin', 'right_margin']);
            \$table->index('depth');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
    }

    /**
     * Get migration filename
     */
    public function getFilename(IBlock $iblock): string
    {
        $tableName = $this->naming->toSectionTableName($iblock->code);

        return $this->naming->toMigrationFileName($tableName);
    }
}
