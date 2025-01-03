<?php

namespace Sharky\Joomla\PluginBuildScript;

class Script
{
	protected string $pluginDirectory;
	protected string $mediaDirectory;
	protected string $pluginName;
	protected string $repositoryUrl;
	protected string $zipFile;
	protected string $manifestFile;

	public function __construct(
		protected string $rootPath,
		protected string $buildDirectory,
		protected string $pluginElement,
		protected string $pluginType,
		protected string $repositoryName,
		protected string $maintainer,
		protected string $updateName,
		protected string $updateDescription,
		protected string $joomlaRegex,
		protected string $phpMinimum,
		protected ?string $version = null,
	)
	{
		$this->pluginName = 'plg_' . $this->pluginType . '_' . $this->pluginElement;

		$this->pluginDirectory = $this->rootPath . '/code/plugins/' . $this->pluginType . '/' . $this->pluginElement;
		$this->mediaDirectory = $this->rootPath . '/code/media/' . $this->pluginName;
		$this->manifestFile = $this->pluginDirectory . '/' . $this->pluginElement . '.xml';

		if ($this->version === null)
		{
			$xml = simplexml_load_file($this->manifestFile);
			$this->version = (string) $xml->version;
		}

		$this->repositoryUrl = 'https://github.com/' . $this->maintainer . '/' . $this->repositoryName;
		$this->zipFile = $this->buildDirectory . '/packages/' . $this->pluginName . '-' . $this->version . '.zip';
	}

	public function build(): void
	{
		$this->updateManifest();
		$this->buildZip();
		$this->updateUpdateXml();
		$this->updateChangelogXml();
	}

	protected function updateManifest(): void
	{
		$xml = simplexml_load_file($this->manifestFile);

		if ((string) $xml->xpath('version')[0] === $this->version)
		{
			return;
		}

		$xml = file_get_contents($this->manifestFile);
		$xml = preg_replace(
			['/<version>.+<\/version>/', '/<creationDate>.+<\/creationDate>/'],
			['<version>' . $this->version . '</version>', '<creationDate>' . date('Y-m-d') . '</creationDate>'],
			$xml
		);

		file_put_contents($this->manifestFile, $xml);
	}

	protected function buildZip(): void
	{
		if (!is_dir($this->buildDirectory . '/packages'))
		{
			mkdir($this->buildDirectory . '/packages', 0755);
		}

		$zip = new \ZipArchive;
		$zip->open($this->zipFile, \ZipArchive::OVERWRITE|\ZipArchive::CREATE);

		$iterator = new \RecursiveDirectoryIterator($this->pluginDirectory);
		$iterator2 = new \RecursiveIteratorIterator($iterator);

		foreach ($iterator2 as $file)
		{
			if ($file->isFile())
			{
				$zip->addFile(
					$file->getPathName(),
					str_replace(['\\', $this->pluginDirectory . '/'], ['/', ''], $file->getPathName())
				);
			}
		}

		if (is_dir($this->mediaDirectory))
		{
			$iterator = new \RecursiveDirectoryIterator($this->mediaDirectory);
			$iterator2 = new \RecursiveIteratorIterator($iterator);

			foreach ($iterator2 as $file)
			{
				if ($file->isFile())
				{
					$zip->addFile(
						$file->getPathName(),
						str_replace(['\\', $this->mediaDirectory . '/'], ['/', 'media/'], $file->getPathName())
					);
				}
			}
		}

		if (is_file($this->rootPath . '/LICENSE'))
		{
			$zip->addFile($this->rootPath . '/LICENSE', 'LICENSE');
		}

		$zip->close();
	}

	protected function updateUpdateXml(): void
	{
		$manifestFile = $this->rootPath . '/updates/updates.xml';
		$xml = is_file($manifestFile) ? simplexml_load_file($manifestFile) : new \SimpleXMLElement('<updates/>');

		$children = $xml->xpath('update');

		foreach ($children as $update)
		{
			if (
				(string) $update->version === $this->version
				|| ((string) $update->targetplatform->attributes()['version'] === $this->joomlaRegex && (string) $update->php_minimum === $this->phpMinimum)
			)
			{
				unset($update[0]);
			}
		}

		//  Static values.
		$update = $xml->addChild('update');
		$update->addChild('name', $this->updateName);
		$update->addChild('description', $this->updateDescription);
		$update->addChild('element', $this->pluginElement);
		$update->addChild('type', 'plugin');
		$update->addChild('folder', $this->pluginType);
		$update->addChild('client', 'site');
		$update->addChild('maintainer', $this->maintainer);
		$update->addChild('maintainerurl', $this->repositoryUrl);

		// Dynamic values.
		$update->addChild('version', $this->version);
		$node = $update->addChild('downloads');
		$node = $node->addChild('downloadurl', $this->repositoryUrl . '/releases/download/' . $this->version . '/' . basename($this->zipFile));
		$node->addAttribute('type', 'full');
		$node->addAttribute('format', 'zip');

		foreach (array_intersect(['sha512', 'sha384', 'sha256'], hash_algos()) as $algo)
		{
			$update->addChild($algo, hash_file($algo, $this->zipFile));
		}

		$node = $update->addChild('infourl', $this->repositoryUrl . '/releases/tag/' . $this->version);
		$node->addAttribute('title', $this->updateName);
		$update->addChild(
			'changelogurl',
			'https://raw.githubusercontent.com/' . $this->maintainer . '/' . $this->repositoryName . '/master/updates/changelog.xml'
		);

		// System requirements.
		$node = $update->addChild('targetplatform');
		$node->addAttribute('name', 'joomla');
		$node->addAttribute('version', $this->joomlaRegex);
		$update->addChild('php_minimum', $this->phpMinimum);

		if (!is_dir(dirname($manifestFile)))
		{
			mkdir(dirname($manifestFile), 0777, true);
		}

		file_put_contents($manifestFile, $this->formatXml($xml->asXml()));
	}

	protected function updateChangelogXml(): void
	{
		$manifestFile = $this->rootPath . '/updates/changelog.xml';
		$xml = is_file($manifestFile) ? simplexml_load_file($manifestFile) : new \SimpleXMLElement('<changelogs/>');

		foreach ($xml->children() as $update)
		{
			if ((string) $update->version === $this->version)
			{
				return;
			}
		}

		$changelog = $xml->addChild('changelog');
		$changelog->addChild('element', $this->pluginElement);
		$changelog->addChild('type', 'plugin');
		$changelog->addChild('version', $this->version);

		if (!is_dir(dirname($manifestFile)))
		{
			mkdir(dirname($manifestFile), 0777, true);
		}

		file_put_contents($manifestFile, $this->formatXml($xml->asXml()));
	}

	protected function formatXml(string $xml): string
	{
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXml($xml);
		$output = $dom->saveXML();

		return str_replace('  ', "\t", $output);
	}
}
