<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Schema;

use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockSection;
use PHPUnit\Framework\TestCase;

final class IBlockTest extends TestCase
{
    private function property(int $id, string $code, string $type, string $multiple = 'N', ?string $userType = null): IBlockProperty
    {
        return IBlockProperty::fromArray([
            'ID' => $id, 'IBLOCK_ID' => 1, 'NAME' => $code, 'CODE' => $code, 'PROPERTY_TYPE' => $type,
            'MULTIPLE' => $multiple, 'USER_TYPE' => $userType,
        ]);
    }

    public function test_an_iblock_row_is_read_with_defaults(): void
    {
        $full = IBlock::fromArray([
            'ID' => '2', 'CODE' => 'products', 'NAME' => 'Products', 'IBLOCK_TYPE_ID' => 'catalog', 'VERSION' => '2',
            'XML_ID' => 'p', 'SORT' => '20', 'ACTIVE' => 'N', 'LIST_PAGE_URL' => '/l/', 'DETAIL_PAGE_URL' => '/d/',
            'SECTION_PAGE_URL' => '/s/',
        ]);
        $this->assertSame(
            [2, 'products', 'Products', 'catalog', 2, 'p', 20, false, '/l/', '/d/', '/s/'],
            [$full->id, $full->code, $full->name, $full->iblockType, $full->version, $full->xmlId, $full->sort, $full->active, $full->listPageUrl, $full->detailPageUrl, $full->sectionPageUrl],
        );

        $bare = IBlock::fromArray(['ID' => 9, 'NAME' => 'Untitled']);
        $this->assertSame(
            ['IBLOCK_9', '', 1, null, 500, true, null, null, null],
            [$bare->code, $bare->iblockType, $bare->version, $bare->xmlId, $bare->sort, $bare->active, $bare->listPageUrl, $bare->detailPageUrl, $bare->sectionPageUrl],
        );
    }

    public function test_an_empty_iblock_has_none_of_anything(): void
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'NAME' => 'Empty']);

        $this->assertNull($iblock->getPropertyByCode('PRICE'));
        $this->assertFalse($iblock->hasFileProperties());
        $this->assertFalse($iblock->hasMultipleProperties());
        $this->assertSame([], $iblock->getFileProperties());
        $this->assertSame([], $iblock->getListProperties());
        $this->assertSame([], $iblock->getElementLinkProperties());
        $this->assertSame([], $iblock->getSectionLinkProperties());
        $this->assertSame([], $iblock->getDirectoryProperties());
    }

    public function test_properties_are_found_by_code_and_grouped_by_kind(): void
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'NAME' => 'Products']);
        $properties = [
            $price = $this->property(1, 'PRICE', 'N'),
            $photo = $this->property(2, 'PHOTO', 'F'),
            $color = $this->property(3, 'COLOR', 'L'),
            $related = $this->property(4, 'RELATED', 'E', 'Y'),
            $category = $this->property(5, 'CATEGORY', 'G'),
            $brand = $this->property(6, 'BRAND', 'S', 'N', 'directory'),
        ];

        foreach ($properties as $property) {
            $iblock->addProperty($property);
        }

        $this->assertSame($properties, $iblock->properties);
        $this->assertSame($color, $iblock->getPropertyByCode('COLOR'));
        $this->assertSame($price, $iblock->getPropertyByCode('PRICE'));
        $this->assertNull($iblock->getPropertyByCode('color'));
        $this->assertTrue($iblock->hasFileProperties());
        $this->assertTrue($iblock->hasMultipleProperties());
        $this->assertSame([1 => $photo], $iblock->getFileProperties());
        $this->assertSame([2 => $color], $iblock->getListProperties());
        $this->assertSame([3 => $related], $iblock->getElementLinkProperties());
        $this->assertSame([4 => $category], $iblock->getSectionLinkProperties());
        $this->assertSame([5 => $brand], $iblock->getDirectoryProperties());
    }

    public function test_an_iblock_of_single_non_file_properties_has_neither_files_nor_multiples(): void
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'NAME' => 'News']);
        $iblock->addProperty($this->property(1, 'SOURCE', 'S'));
        $iblock->addProperty($this->property(2, 'PRICE', 'N'));

        $this->assertFalse($iblock->hasFileProperties());
        $this->assertFalse($iblock->hasMultipleProperties());
    }

    public function test_sections_are_read_and_know_their_place_in_the_tree(): void
    {
        $root = IBlockSection::fromArray([
            'ID' => '1', 'IBLOCK_ID' => '2', 'IBLOCK_SECTION_ID' => null, 'NAME' => 'Hardware', 'CODE' => 'hardware',
            'XML_ID' => 'hw', 'SORT' => '10', 'ACTIVE' => 'N', 'DEPTH_LEVEL' => '1', 'LEFT_MARGIN' => '1',
            'RIGHT_MARGIN' => '4', 'DESCRIPTION' => '<p>Touch</p>', 'DESCRIPTION_TYPE' => 'html', 'PICTURE' => '7',
            'DETAIL_PICTURE' => '8',
        ]);
        $this->assertSame(
            [1, 2, null, 'Hardware', 'hardware', 'hw', 10, false, 1, 1, 4, '<p>Touch</p>', 'html', 7, 8],
            [$root->id, $root->iblockId, $root->parentId, $root->name, $root->code, $root->xmlId, $root->sort, $root->active, $root->depth, $root->leftMargin, $root->rightMargin, $root->description, $root->descriptionType, $root->pictureId, $root->detailPictureId],
        );
        $this->assertTrue($root->isRoot());
        $this->assertFalse($root->hasChildren());

        $child = IBlockSection::fromArray(['ID' => 2, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => '1', 'NAME' => 'Laptops', 'PICTURE' => 0]);
        $this->assertSame(
            [1, null, null, 500, true, 0, 0, 0, null, null, null, null],
            [$child->parentId, $child->code, $child->xmlId, $child->sort, $child->active, $child->depth, $child->leftMargin, $child->rightMargin, $child->description, $child->descriptionType, $child->pictureId, $child->detailPictureId],
        );
        $this->assertFalse($child->isRoot());

        $root->addChild($child);
        $this->assertTrue($root->hasChildren());
        $this->assertSame([$child], $root->children);

        $iblock = IBlock::fromArray(['ID' => 2, 'NAME' => 'Products']);
        $iblock->addSection($root);
        $this->assertSame([$root], $iblock->sections);
    }
}
