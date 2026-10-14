<?php

namespace App\Support\Imports;

use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;

/**
 * The look of the spreadsheets the system writes (the import template, the file of rejected rows):
 * the tokens of the web theme (docs/design/frontend.md 1.3) as RGB, so a file opened in Excel
 * belongs to the same family as the pages. Colours here only; the cells carry no logic.
 */
final class SheetStyles
{
    /** `primary` */
    public const PRIMARY = '5468D4';

    /** `primary-soft` */
    public const PRIMARY_SOFT = 'E3E8FF';

    /** `primary-ink`, the dark text on a soft primary surface */
    public const PRIMARY_INK = '2E3FA8';

    /** `ink` */
    public const INK = '14162B';

    /** The pale row background of the pages' tables */
    public const ROW_SOFT = 'F4F5FD';

    /** `line` */
    public const LINE = 'DDE0F2';

    /** The accent of the cta buttons, used for the columns the system adds */
    public const ACCENT = 'F29A76';

    /** The reason column of the error file: a soft red surface and its ink */
    public const BAD_SOFT = 'FFE3E0';

    public const BAD_INK = '8E1B12';

    /** Row heights, in points: a header and a body row. */
    public const HEADER_HEIGHT = 30.0;

    public const ROW_HEIGHT = 22.0;

    /** The header of the columns the user fills: primary background, white bold text. */
    public static function header(): Style
    {
        return self::base()
            ->withBackgroundColor(self::PRIMARY)
            ->withFontColor('FFFFFF')
            ->withFontBold(true)
            ->withFontSize(12);
    }

    /** The header of the columns the system adds (line, reason): accent background, dark text. */
    public static function addedHeader(): Style
    {
        return self::base()
            ->withBackgroundColor(self::ACCENT)
            ->withFontColor(self::INK)
            ->withFontBold(true)
            ->withFontSize(12);
    }

    /** A body cell; even rows (0, 2, ...) are white, odd ones pale, as the zebra of the tables. */
    public static function body(int $index): Style
    {
        $style = self::base()->withFontColor(self::INK)->withFontSize(11);

        return $index % 2 === 1 ? $style->withBackgroundColor(self::ROW_SOFT) : $style;
    }

    /** The first cell of an instruction row: the column name, soft primary and bold. */
    public static function columnName(): Style
    {
        return self::base()
            ->withBackgroundColor(self::PRIMARY_SOFT)
            ->withFontColor(self::PRIMARY_INK)
            ->withFontBold(true)
            ->withFontSize(11);
    }

    /** The reason of a rejected row. */
    public static function reason(): Style
    {
        return self::base()
            ->withBackgroundColor(self::BAD_SOFT)
            ->withFontColor(self::BAD_INK)
            ->withFontSize(11)
            ->withShouldWrapText(true);
    }

    private static function base(): Style
    {
        $part = static fn (BorderName $name): BorderPart => new BorderPart($name, self::LINE, BorderWidth::THIN, BorderStyle::SOLID);

        return (new Style)
            ->withCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->withBorder(new Border($part(BorderName::TOP), $part(BorderName::BOTTOM), $part(BorderName::LEFT), $part(BorderName::RIGHT)));
    }
}
