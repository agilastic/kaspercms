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

namespace TYPO3\CMS\Form\Tests\Functional\Mvc\Property;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Factory\ArrayFormFactory;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Mvc\Property\TypeConverter\UploadedFileReferenceConverter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The upload folder is resolved in afterBuildingFinished(), after the element's
 * own properties are applied (CVE-2026-109162 affects TYPO3 14.x only).
 */
final class PropertyMappingConfigurationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['form'];

    protected array $additionalFoldersToCreate = [
        '/fileadmin/user_upload',
        '/fileadmin/custom_uploads',
        '/fileadmin/form_definitions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $request = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->get(ExtbaseConfigurationManagerInterface::class)->setRequest($request);
    }

    public static function uploadElementTypesDataProvider(): array
    {
        return [
            'FileUpload' => ['FileUpload'],
            'ImageUpload' => ['ImageUpload'],
        ];
    }

    #[DataProvider('uploadElementTypesDataProvider')]
    #[Test]
    public function saveToFileMountOfFormElementIsUsedAsUploadFolder(string $type): void
    {
        $formDefinition = $this->buildFormDefinition($type, ['saveToFileMount' => '1:/custom_uploads/']);

        self::assertSame('1:/custom_uploads/', $this->getUploadFolder($formDefinition));
    }

    #[DataProvider('uploadElementTypesDataProvider')]
    #[Test]
    public function folderOfFormDefinitionIsUsedAsUploadFolderIfSaveToFileMountIsEmpty(string $type): void
    {
        $formDefinition = $this->buildFormDefinition($type, ['saveToFileMount' => ''], '1:/form_definitions/contact.form.yaml');

        self::assertSame('1:/form_definitions', $this->getUploadFolder($formDefinition));
    }

    #[Test]
    public function defaultSaveToFileMountIsUsedAsUploadFolder(): void
    {
        $formDefinition = $this->buildFormDefinition('FileUpload', []);

        self::assertSame('1:/user_upload/', $this->getUploadFolder($formDefinition));
    }

    private function buildFormDefinition(string $type, array $properties, ?string $persistenceIdentifier = null): FormDefinition
    {
        $configuration = [
            'identifier' => 'test-form',
            'prototypeName' => 'standard',
            'type' => 'Form',
            'renderables' => [
                [
                    'identifier' => 'page-1',
                    'type' => 'Page',
                    'renderables' => [
                        [
                            'identifier' => 'file-1',
                            'type' => $type,
                            'properties' => $properties,
                        ],
                    ],
                ],
            ],
        ];
        if ($persistenceIdentifier !== null) {
            $configuration['persistenceIdentifier'] = $persistenceIdentifier;
        }
        return $this->get(ArrayFormFactory::class)->build($configuration);
    }

    private function getUploadFolder(FormDefinition $formDefinition): mixed
    {
        return $formDefinition->getProcessingRule('file-1')
            ->getPropertyMappingConfiguration()
            ->getConfigurationValue(UploadedFileReferenceConverter::class, UploadedFileReferenceConverter::CONFIGURATION_UPLOAD_FOLDER);
    }
}
