<?php

namespace modules\main\twigextensions;

use Craft;
use Illuminate\Support\Collection;
use modules\main\CustomConfig;
use modules\main\services\ProjectService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension implements GlobalsInterface
{

    public function getGlobals(): array
    {
        return  [
            // TODO: Remove _globals in Craft 4.5
            '_globals' => Collection::make(),
            'customConfig' => Craft::$app->getConfig()->custom,
        ];
    }

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('quotationMarks', fn(?string $text): string => $this->quotationMarksFilter($text))
        ];
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('estimatedReadingTime', function ($blocks, $wpm = 200) {
                return ProjectService::estimatedReadingTime($blocks, $wpm);
            }),
        ];
    }

    public function quotationMarksFilter(?string $text): string
    {
        return Craft::t('site', '“') . $text . Craft::t('site', '”');
    }

}
