<?hh // strict
/**
 * Copyright (c) 2015-present, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional grant
 * of patent rights can be found in the PATENTS file in the same directory.
 */

namespace Facebook\HackCodegen;

final class CodegenFileTest extends CodegenBaseTest {
  public function testAutogenerated(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setDocBlock('Completely autogenerated!')
      ->addClass(
        $cgf
          ->codegenClass('AllAutogenerated')
          ->addMethod(
            $cgf
              ->codegenMethod('getName')
              ->setReturnType('string')
              ->setBody('return $this->name;'),
          ),
      )
      ->render();

    $this->assertUnchanged($code);
  }

  public function testGenerateTopLevelFunctions(): void {
    $cgf = $this->getCodegenFactory();
    $function =
      $cgf->codegenFunction('fun')->setReturnType('int')->setBody('return 0;');
    $code = $cgf->codegenFile('no_file')->addFunction($function)->render();

    $this->assertUnchanged($code);
  }

  public function testPartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->addClass(
        $cgf
          ->codegenClass('PartiallyGenerated')
          ->addMethod($cgf->codegenMethod('getSomething')->setManualBody()),
      )
      ->addClass(
        $cgf
          ->codegenClass('PartiallyGeneratedLoader')
          ->setDocBlock('We can put many clases in one file!'),
      )
      ->render();

    $this->assertUnchanged($code);
  }

  private function saveAutogeneratedFile(?string $fname = null): string {
    $cgf = $this->getCodegenFactory();
    if (Str::isEmpty($fname)) {
      $fname = Filesystem::createTemporaryFile('codegen', true);
    }
    assert($fname !== null);

    $cgf
      ->codegenFile($fname)
      ->setDocBlock('Testing CodegenFile with autogenerated files')
      ->addClass(
        $cgf
          ->codegenClass('Demo')
          ->addMethod($cgf
            ->codegenMethod('getName')
            ->setBody('return "Codegen";')),
      )
      ->save();

    return $fname;
  }

  private function saveManuallyWrittenFile(?string $fname = null): string {
    if (Str::isEmpty($fname)) {
      $fname = Filesystem::createTemporaryFile('codegen', true);
    }
    assert($fname !== null);

    Filesystem::writeFileIfChanged(
      $fname,
      "<?php\n"."// Some handwritten code",
    );
    return $fname;
  }

  private function savePartiallyGeneratedFile(
    ?string $fname = null,
    bool $extra_method = false,
  ): string {
    $cgf = $this->getCodegenFactory();

    if (Str::isEmpty($fname)) {
      $fname = Filesystem::createTemporaryFile('codegen', true);
    }
    assert($fname !== null);

    $class = $cgf
      ->codegenClass('Demo')
      ->addMethod(
        $cgf
          ->codegenMethod('getName')
          ->setBody('// manual_section_here')
          ->setManualBody(),
      );

    if ($extra_method) {
      $class->addMethod($cgf->codegenMethod('extraMethod')->setManualBody());
    }

    $cgf
      ->codegenFile($fname)
      ->setDocBlock('Testing CodegenFile with partially generated files')
      ->addClass($class)
      ->save();

    return $fname;
  }

  public function testSaveAutogenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->saveAutogeneratedFile();
    $this->assertUnchanged(Filesystem::readFile($fname));
  }

  public function testClobberManuallyWrittenCode(): void {
    $cgf = $this->getCodegenFactory();
    $this->expectException(CodegenFileNoSignatureException::class);

    $fname = $this->saveManuallyWrittenFile();
    $this->saveAutogeneratedFile($fname);
  }

  public function testReSaveAutogenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->saveAutogeneratedFile();
    $content0 = Filesystem::readFile($fname);
    $this->saveAutogeneratedFile($fname);
    $content1 = Filesystem::readFile($fname);
    $this->assertEquals($content0, $content1);
  }

  public function testSaveModifiedAutogenerated(): void {
    $cgf = $this->getCodegenFactory();
    $this->expectException(CodegenFileBadSignatureException::class);

    $fname = $this->saveAutogeneratedFile();
    $content = Filesystem::readFile($fname);
    Filesystem::writeFile($fname, $content.'.');
    $this->saveAutogeneratedFile($fname);
  }


  public function testSavePartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->savePartiallyGeneratedFile();
    $content = Filesystem::readFile($fname);
    $this->assertUnchanged($content);
    $this->assertTrue(
      PartiallyGeneratedSignedSource::hasValidSignature($content),
    );
  }

  public function testReSavePartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->savePartiallyGeneratedFile();
    $content0 = Filesystem::readFile($fname);
    $this->savePartiallyGeneratedFile($fname);
    $content1 = Filesystem::readFile($fname);
    $this->assertEquals($content0, $content1);
  }

  public function testSaveModifiedWrongPartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $this->expectException(CodegenFileBadSignatureException::class);

    $fname = $this->savePartiallyGeneratedFile();
    $content = Filesystem::readFile($fname);
    Filesystem::writeFile($fname, $content.'.');
    $this->saveAutogeneratedFile($fname);
  }

  private function createAndModifyPartiallyGeneratedFile(): string {
    $fname = $this->savePartiallyGeneratedFile();
    $content = Filesystem::readFile($fname);

    $new_content =
      str_replace('// manual_section_here', 'return $this->name;', $content);
    $this->assertFalse(
      $content == $new_content,
      "The manual content wasn't replaced. Please fix the test setup!",
    );
    Filesystem::writeFile($fname, $new_content);
    return $fname;
  }

  /**
   * Test modifying a manual section and saving.
   */
  public function testSaveModifiedManualSectionPartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->createAndModifyPartiallyGeneratedFile();
    $this->savePartiallyGeneratedFile($fname);
    $content = Filesystem::readFile($fname);
    $this->assertTrue(strpos($content, 'this->name') !== false);
  }

  /**
   * Test modifying a manual section and changing the code generation so
   * that the generated part is different too.
   */
  public function testSaveModifyPartiallyGenerated(): void {
    $cgf = $this->getCodegenFactory();
    $fname = $this->createAndModifyPartiallyGeneratedFile();
    $this->savePartiallyGeneratedFile($fname, true);
    $content = Filesystem::readFile($fname);
    $this->assertTrue(strpos($content, 'return $this->name;') !== false);
    $this->assertTrue(strpos($content, 'function extraMethod()') !== false);
  }

  public function testNoSignature(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setIsSignedFile(false)
      ->setDocBlock('Completely autogenerated!')
      ->addClass(
        $cgf
          ->codegenClass('NoSignature')
          ->addMethod(
            $cgf
              ->codegenMethod('getName')
              ->setReturnType('string')
              ->setBody('return $this->name;'),
          ),
      )
      ->render();

    $this->assertUnchanged($code);
  }

  public function testNamespace(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setNamespace('MyNamespace')
      ->useNamespace('Another\Space')
      ->useClass('My\Space\Bar', 'bar')
      ->useFunction('My\Space\my_function', 'f')
      ->useConst('My\Space\MAX_RETRIES')
      ->addClass($cgf->codegenClass('Foo'))
      ->render();

    $this->assertUnchanged($code);
  }

  public function testStrictFile(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setIsStrict(true)
      ->addClass($cgf->codegenClass('Foo'))
      ->render();

    $this->assertUnchanged($code);
  }

  public function testPhpFile(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setFileType(CodegenFileType::PHP)
      ->addClass($cgf->codegenClass('Foo'))
      ->render();

    $this->assertUnchanged($code);
  }

  public function testExecutable(): void {
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setFileType(CodegenFileType::HACK_PARTIAL)
      ->setShebangLine('#!/usr/bin/env hhvm')
      ->setPseudoMainHeader('require_once(\'vendor/autoload.php\');')
      ->addFunction(
        $cgf
          ->codegenFunction('main')
          ->setReturnType('void')
          ->setBody('print("Hello, world!\n");'),
      )
      ->setPseudoMainFooter('main();')
      ->render();
    $this->assertUnchanged($code);
  }

  public function testNoShebangInStrict(): void {
    $this->expectException(
      /* HH_FIXME[2049] no hhi for invariantexception */
      \HH\InvariantException::class,
    );
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setFileType(CodegenFileType::HACK_STRICT)
      ->setShebangLine('#!/usr/bin/env hhvm')
      ->render();
  }

  public function testNoPseudoMainHeaderInStrict(): void {
    $this->expectException(
      /* HH_FIXME[2049] no hhi for invariantexception */
      \HH\InvariantException::class,
    );
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setFileType(CodegenFileType::HACK_STRICT)
      ->setPseudoMainHeader('exit();')
      ->render();
  }

  public function testNoPseudoMainFooterInStrict(): void {
    $this->expectException(
      /* HH_FIXME[2049] no hhi for invariantexception */
      \HH\InvariantException::class,
    );
    $cgf = $this->getCodegenFactory();
    $code = $cgf
      ->codegenFile('no_file')
      ->setFileType(CodegenFileType::HACK_STRICT)
      ->setPseudoMainFooter('exit();')
      ->render();
  }
}
