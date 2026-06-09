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

namespace TYPO3\CMS\Form\Tests\Functional\Mvc\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FormPersistenceManagerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3/sysext/form/Tests/Functional/Mvc/Persistence/Fixtures/ContactForm.form.yaml' => 'fileadmin/form_definitions/ContactForm.form.yaml',
        'typo3/sysext/form/Tests/Functional/Mvc/Persistence/Fixtures/ContactFormWithoutType.yaml' => 'fileadmin/form_definitions/ContactFormWithoutType.yaml',
        'typo3/sysext/form/Tests/Functional/Mvc/Persistence/Fixtures/ContactFormWithType.yaml' => 'fileadmin/form_definitions/ContactFormWithType.yaml',
    ];

    public static function loadThrowsExceptionWhenFormDefinitionHasInvalidFileExtensionDataProvider(): iterable
    {
        yield ['1:/form_definitions/ContactForm.form.yaml', null];
        yield ['1:/form_definitions/ContactFormWithType.yaml', true];
        yield ['1:/form_definitions/ContactFormWithoutType.yaml', true];
    }

    #[Test]
    #[DataProvider('loadThrowsExceptionWhenFormDefinitionHasInvalidFileExtensionDataProvider')]
    public function loadThrowsExceptionWhenFormDefinitionHasInvalidFileExtension(string $identifier, ?bool $expectedInvalid): void
    {
        $subject = $this->get(FormPersistenceManager::class);
        $formDefinition = $subject->load($identifier);
        self::assertSame($expectedInvalid, $formDefinition['invalid'] ?? null);
    }
}
