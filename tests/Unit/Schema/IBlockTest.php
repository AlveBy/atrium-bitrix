<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Schema;

use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockElement;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockSection;
use DateTimeImmutable;
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

    public function test_an_element_row_is_read_with_its_dates(): void
    {
        $element = IBlockElement::fromArray([
            'ID' => '5', 'IBLOCK_ID' => '2', 'IBLOCK_SECTION_ID' => '3', 'NAME' => 'Laptop', 'CODE' => 'laptop',
            'XML_ID' => 'x5', 'SORT' => '30', 'ACTIVE' => 'N', 'PREVIEW_TEXT' => 'Short', 'PREVIEW_TEXT_TYPE' => 'text',
            'PREVIEW_PICTURE' => '11', 'DETAIL_TEXT' => '<p>Long</p>', 'DETAIL_TEXT_TYPE' => 'html', 'DETAIL_PICTURE' => '12',
            'TAGS' => 'a, b', 'CREATED_BY' => '1', 'MODIFIED_BY' => '2', 'DATE_CREATE' => '2024-02-01 09:00:00',
            'TIMESTAMP_X' => '2024-02-02 10:00:00', 'DATE_ACTIVE_FROM' => '2024-03-01 00:00:00',
            'DATE_ACTIVE_TO' => '2024-04-01 00:00:00',
        ]);

        $this->assertSame(
            [5, 2, 3, 'Laptop', 'laptop', 'x5', 30, false, 'Short', 'text', 11, '<p>Long</p>', 'html', 12, 'a, b', 1, 2],
            [$element->id, $element->iblockId, $element->sectionId, $element->name, $element->code, $element->xmlId, $element->sort, $element->active, $element->previewText, $element->previewTextType, $element->previewPictureId, $element->detailText, $element->detailTextType, $element->detailPictureId, $element->tags, $element->createdBy, $element->modifiedBy],
        );
        $this->assertSame('2024-02-01 09:00:00', $element->dateCreate->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-02 10:00:00', $element->timestampX->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-01', $element->activeFrom?->format('Y-m-d'));
        $this->assertSame('2024-04-01', $element->activeTo?->format('Y-m-d'));
    }

    public function test_a_bare_element_row_is_dated_now_and_has_no_period(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $element = IBlockElement::fromArray(['ID' => 1, 'IBLOCK_ID' => 2, 'NAME' => 'Bare', 'IBLOCK_SECTION_ID' => 0, 'PREVIEW_PICTURE' => '', 'CREATED_BY' => 0]);

        $this->assertSame(
            [null, null, null, 500, true, null, null, null, null, null, null, null, null, null, null, null],
            [$element->sectionId, $element->code, $element->xmlId, $element->sort, $element->active, $element->previewText, $element->previewTextType, $element->previewPictureId, $element->detailText, $element->detailTextType, $element->detailPictureId, $element->tags, $element->createdBy, $element->modifiedBy, $element->activeFrom, $element->activeTo],
        );
        $this->assertGreaterThanOrEqual($before, $element->dateCreate);
        $this->assertGreaterThanOrEqual($before, $element->timestampX);
    }

    public function test_element_properties_and_sections(): void
    {
        $element = IBlockElement::fromArray(['ID' => 1, 'IBLOCK_ID' => 2, 'NAME' => 'Laptop']);

        $this->assertNull($element->getProperty('PRICE'));
        $element->setProperty('PRICE', 10.5);
        $element->setProperty('TAGS', ['a']);
        $this->assertSame(10.5, $element->getProperty('PRICE'));
        $this->assertSame(['PRICE' => 10.5, 'TAGS' => ['a']], $element->properties);

        $this->assertFalse($element->isInMultipleSections());
        $element->addSectionId(3);
        $element->addSectionId(3);
        $this->assertSame([3], $element->sectionIds);
        $this->assertFalse($element->isInMultipleSections());
        $element->addSectionId(4);
        $this->assertSame([3, 4], $element->sectionIds);
        $this->assertTrue($element->isInMultipleSections());
    }
}
