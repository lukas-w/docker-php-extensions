<?php

namespace builder;

use dpe\builder\JobMatrix;
use PHPUnit\Framework\TestCase;

class JobMatrixTest extends TestCase
{
	// Testing using GitHub's examples
	public function testBasicMatrix(): void
	{
		// https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/run-job-variations#adding-a-matrix-strategy-to-your-workflow-job
		$m = new JobMatrix(
			vars: [
				'version' => [10, 12, 14],
				'os'      => ['ubuntu-latest', 'windows-latest'],
			],
		);
		$configs = [...$m->configs()];
		$this->assertCount(6, $configs);
		$this->assertContainsEquals(['version' => 10, 'os' => 'ubuntu-latest'], $configs);
		$this->assertContainsEquals(['version' => 10, 'os' => 'windows-latest'], $configs);
		$this->assertContainsEquals(['version' => 12, 'os' => 'ubuntu-latest'], $configs);
		$this->assertContainsEquals(['version' => 12, 'os' => 'windows-latest'], $configs);
		$this->assertContainsEquals(['version' => 14, 'os' => 'ubuntu-latest'], $configs);
		$this->assertContainsEquals(['version' => 14, 'os' => 'windows-latest'], $configs);
	}

	public function testExpanding(): void
	{
		// https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/run-job-variations#expanding-or-adding-matrix-configurations
		$m = new JobMatrix(
			vars: [
				'fruit'  => ['apple', 'pear'],
				'animal' => ['cat', 'dog'],
			],
			include: [
				[
					'color' => 'green',
				],
				[
					'color'  => 'pink',
					'animal' => 'cat',
				],
				[
					'fruit' => 'apple',
					'shape' => 'circle',
				],
				[
					'fruit' => 'banana',
				],
				[
					'fruit'  => 'banana',
					'animal' => 'cat',
				],
			],
		);
		$configs = [...$m->configs()];
		$this->assertCount(6, $configs);
		$this->assertContainsEquals(
			['fruit' => 'apple', 'animal' => 'cat', 'color' => 'pink', 'shape' => 'circle'],
			$configs
		);
		$this->assertContainsEquals(
			['fruit' => 'apple', 'animal' => 'dog', 'color' => 'green', 'shape' => 'circle'],
			$configs
		);
		$this->assertContainsEquals(['fruit' => 'pear', 'animal' => 'cat', 'color' => 'pink'], $configs);
		$this->assertContainsEquals(['fruit' => 'pear', 'animal' => 'dog', 'color' => 'green'], $configs);
		$this->assertContainsEquals(['fruit' => 'banana'], $configs);
		$this->assertContainsEquals(['fruit' => 'banana', 'animal' => 'cat'], $configs);
	}

	public function testExcluding(): void
	{
		// https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/run-job-variations#excluding-matrix-configurations
		$m = new JobMatrix(
			vars: [
				'os'          => ['macos-latest', 'windows-latest'],
				'version'     => [12, 14, 16],
				'environment' => ['staging', 'production'],
			],
			exclude: [
				[
					'os'          => 'macos-latest',
					'version'     => 12,
					'environment' => 'production',
				],
				[
					'os'      => 'windows-latest',
					'version' => 16,
				],
			],
		);
		$configs = [...$m->configs()];
		$this->assertCount(9, $configs);
		$this->assertContainsEquals(['os' => 'macos-latest', 'version' => 12, 'environment' => 'staging'], $configs);
		$this->assertContainsEquals(['os' => 'macos-latest', 'version' => 14, 'environment' => 'staging'], $configs);
		$this->assertContainsEquals(['os' => 'macos-latest', 'version' => 14, 'environment' => 'production'], $configs);
		$this->assertContainsEquals(['os' => 'macos-latest', 'version' => 16, 'environment' => 'staging'], $configs);
		$this->assertContainsEquals(['os' => 'macos-latest', 'version' => 16, 'environment' => 'production'], $configs);
		$this->assertContainsEquals(['os' => 'windows-latest', 'version' => 12, 'environment' => 'staging'], $configs);
		$this->assertContainsEquals(['os' => 'windows-latest', 'version' => 12, 'environment' => 'production'], $configs);
		$this->assertContainsEquals(['os' => 'windows-latest', 'version' => 14, 'environment' => 'staging'], $configs);
		$this->assertContainsEquals(['os' => 'windows-latest', 'version' => 14, 'environment' => 'production'], $configs);
	}

	public function testExclude(): void
	{
		$m = new JobMatrix(
			vars: [
				'php' => ['7.4', '8.0', '8.1'],
				'ext' => ['extA', 'extB'],
			],
		);
		$m2 = $m->exclude(['php' => '8.0', 'ext' => 'extB']);
		$this->assertEquals($m->vars, $m2->vars);

		$m3 = $m2->exclude(['php' => '8.0', 'ext' => 'extA']);
		$this->assertEquals(['7.4', '8.1'], $m3->vars['php']);
	}

	public function testImplode(): void
	{
		$m = new JobMatrix(
			vars: [
				'php' => ['7.4', '8.0', '8.1'],
				'ext' => ['extA', 'extB'],
				'arch' => ['x86', 'arm'],
			],
		);
		$this->assertCount(12, [...$m->configs()]);
		$m = $m->implode('arch', ',');
		$this->assertCount(6, [...$m->configs()]);
		$this->assertEquals(['x86,arm'], $m->vars['arch']);


		$m = new JobMatrix(
			vars: [
				'php' => ['7.4', '8.0', '8.1'],
				'ext' => ['extA', 'extB'],
				'arch' => ['x86', 'arm'],
			],
		);
		$m = $m->exclude(['ext' => 'extB', 'arch' => 'arm']);
		$this->assertCount(9, [...$m->configs()]);
		$m = $m->implode('arch', '|');
		$configs = [...$m->configs()];
		$this->assertCount(6, $configs);
		$this->assertContainsEquals(['php' => '7.4', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '8.0', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '8.1', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '7.4', 'ext' => 'extB', 'arch' => 'x86'], $configs);
		$this->assertContainsEquals(['php' => '8.0', 'ext' => 'extB', 'arch' => 'x86'], $configs);
		$this->assertContainsEquals(['php' => '8.1', 'ext' => 'extB', 'arch' => 'x86'], $configs);


		$m = new JobMatrix(
			vars: [
				'php' => ['7.4', '8.0', '8.1'],
				'ext' => ['extA', 'extB'],
				'arch' => ['x86', 'arm'],
			],
		);
		$m = $m->exclude(['ext' => 'extB', 'arch' => 'arm', 'php' => '7.4']);
		$this->assertCount(11, [...$m->configs()]);
		$m = $m->implode('arch', '|');
		$configs = [...$m->configs()];
		$this->assertCount(6, $configs);
		$this->assertContainsEquals(['php' => '7.4', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '8.0', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '8.1', 'ext' => 'extA', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '7.4', 'ext' => 'extB', 'arch' => 'x86'], $configs);
		$this->assertContainsEquals(['php' => '8.0', 'ext' => 'extB', 'arch' => 'x86|arm'], $configs);
		$this->assertContainsEquals(['php' => '8.1', 'ext' => 'extB', 'arch' => 'x86|arm'], $configs);
	}
}
