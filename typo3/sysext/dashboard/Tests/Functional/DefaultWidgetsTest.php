<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Dashboard\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Dashboard\DashboardPresetRegistry;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * KasperCMS does not register the "About TYPO3" and "Getting Started with
 * TYPO3" widgets, and the default dashboard has no default widgets.
 */
final class DefaultWidgetsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    #[Test]
    public function typo3InformationWidgetsAreNotRegistered(): void
    {
        $widgets = $this->get(WidgetRegistry::class)->getAllWidgets();
        self::assertArrayNotHasKey('t3information', $widgets);
        self::assertArrayNotHasKey('docGettingStarted', $widgets);
        // Other core widgets are still available
        self::assertArrayHasKey('docTypoScriptReference', $widgets);
    }

    #[Test]
    public function defaultDashboardHasNoDefaultWidgets(): void
    {
        $presets = $this->get(DashboardPresetRegistry::class)->getDashboardPresets();
        self::assertArrayHasKey('default', $presets);
        self::assertSame([], $presets['default']->getDefaultWidgets());
    }
}
