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

enum CodegenFileResult: int {
  NONE = 0;
  UPDATE = 1;
  CREATE = 2;
};

enum CodegenFileType: int {
  PHP = 0;
  HACK_DECL = 1;
  HACK_PARTIAL = 2;
  HACK_STRICT = 3;
}

/**
 * File of generated code. The file is composed by classes.
 * The file will be signed, either as autogenerated or partially generated,
 * depending on whether there are manual sections.
 */
final class CodegenFile {

  private CodegenFileType $fileType = CodegenFileType::HACK_PARTIAL;
  private ?string $docBlock;
  private string $fileName;
  private string $relativeFileName;
  private Vector<string> $otherFileNames = Vector {};
  private Vector<CodegenClassBase> $classes = Vector {};
  private Vector<CodegenTrait> $traits = Vector {};
  private Vector<CodegenFunction> $functions = Vector {};
  private Vector<CodegenType> $beforeTypes = Vector {};
  private Vector<CodegenType> $afterTypes = Vector {};
  private bool $doClobber = false;
  protected ?CodegenGeneratedFrom $generatedFrom;
  private bool $isSignedFile = true;
  private ?Map<string, Vector<string>> $rekey = null;
  private bool $createOnly = false;
  private ?ICodegenFormatter $formatter;
  private ?string $fileNamespace;
  private Map<string, ?string> $useNamespaces = Map {};

  public function __construct(
    private IHackCodegenConfig $config,
    string $file_name,
  ) {
    $root = $config->getRootDir();
    if (!Str::startsWith($file_name, '/')) {
      $this->relativeFileName = $file_name;
      $file_name = $root.'/'.$file_name;
    } else if (Str::startsWith($file_name, $root)) {
      $this->relativeFileName = substr(
        $file_name,
        Str::len($root) + 1,
      );
    } else {
      $this->relativeFileName = $file_name;
    }
    $this->fileName = $file_name;
  }

  /**
   * Use this when refactoring generated code.  Say you're renaming a class, but
   * want to pull the manual code sections from the old file.  Use this.
   */
  public function addOriginalFile(string $file_name): this {
    $this->otherFileNames[] = $file_name;
    return $this;
  }

  public function addClasses(\ConstVector<CodegenClassBase> $classes): this {
    foreach ($classes as $class) {
      $this->addClass($class);
    }
    return $this;
  }

  public function addClass(CodegenClassBase $class): this {
    $this->classes[] = $class;
    return $this;
  }

  public function getClasses(): Vector<CodegenClassBase> {
    return $this->classes;
  }

  public function addTrait(CodegenTrait $trait): this {
    $this->traits[] = $trait;
    return $this;
  }

  public function addFunctions(\ConstVector<CodegenFunction> $functions): this {
    foreach ($functions as $function) {
      $this->addFunction($function);
    }
    return $this;
  }

  public function addFunction(CodegenFunction $function): this {
    $this->functions[] = $function;
    return $this;
  }

  public function getFunctions(): Vector<CodegenFunction> {
    return $this->functions;
  }

  public function addBeforeTypes(Vector<CodegenType> $types): this {
    foreach ($types as $type) {
      $this->addBeforeType($type);
    }
    return $this;
  }

  public function addBeforeType(CodegenType $type): this {
    $this->beforeTypes[] = $type;
    return $this;
  }

  public function getBeforeTypes(): Vector<CodegenType> {
    return $this->beforeTypes;
  }

  public function addAfterTypes(Vector<CodegenType> $types): this {
    foreach ($types as $type) {
      $this->addAfterType($type);
    }
    return $this;
  }

  public function addAfterType(CodegenType $type): this {
    $this->afterTypes[] = $type;
    return $this;
  }

  public function getAfterTypes(): Vector<CodegenType> {
    return $this->afterTypes;
  }


  /**
   * The absolute path.
   */
  public function getFileName(): string {
    return $this->fileName;
  }

  public function getRelativeFileName(): string {
    return $this->relativeFileName;
  }

  public function exists(): bool {
    return file_exists($this->fileName);
  }

  /**
   * Use this to pull manual code from a section keyed by $old_key and
   * place it in a section keyed by $new_key.
   * Note that $old_key could even be in a separate file, if you use
   * addOriginalFile.
   */
  public function rekeyManualSection(string $old_key, string $new_key): this {
    if ($this->rekey === null) {
      $this->rekey = Map {};
    }
    $rekey = $this->rekey;
    if (!$rekey->containsKey($new_key)) {
      $rekey[$new_key] = Vector { $old_key };
    } else {
      $rekey[$new_key][] = $old_key;
    }
    return $this;
  }

  /**
   * Whether the generated file will be Hack strict mode or partial mode.
   * For more flexibility, use setFileType.
   */
  public function setIsStrict(bool $value): this {
    if ($value) {
      $this->setFileType(CodegenFileType::HACK_STRICT);
    } else {
      $this->setFileType(CodegenFileType::HACK_PARTIAL);
    }
    return $this;
  }

  public function setFileType(CodegenFileType $type): this {
    $this->fileType = $type;
    return $this;
  }

  public function setDocBlock(string $comment): this {
    $this->docBlock = $comment;
    return $this;
  }

  public function setIsSignedFile(bool $value): this {
    $this->isSignedFile = $value;
    return $this;
  }

  public function setFormatter(ICodegenFormatter $formatter): this {
    $this->formatter = $formatter;
    return $this;
  }

  public function getFormatter(): ?ICodegenFormatter {
    return $this->formatter;
  }


  private function getFileTypeDeclaration(): string {
    switch($this->fileType) {
      case CodegenFileType::PHP:
        return '<?php';
      case CodegenFileType::HACK_DECL:
        return '<?hh // decl';
      case CodegenFileType::HACK_PARTIAL:
        return '<?hh';
      case CodegenFileType::HACK_STRICT:
        return '<?hh // strict';
    }
  }

  public function render(): string {
    $builder = new HackBuilder($this->config);

    $builder->addLine($this->getFileTypeDeclaration());
    $header = $this->config->getFileHeader();
    if ($header) {
      foreach ($header as $line) {
        $builder->addInlineComment($line);
      }
    }

    $content = $this->getContent();

    if ($this->formatter !== null) {
      $content = $this->formatter->format($content, $this->getFileName());
    }

    if (!$this->isSignedFile) {
      $builder->add($content);
      return $builder->getCode();
    }

    $old_content = $this->loadExistingFiles();

    $doc_block = (string)$this->docBlock;
    $gen_from = $this->generatedFrom;
    if ($gen_from !== null) {
      if ($doc_block && !Str::endsWith($doc_block, "\n")) {
        $doc_block .= "\n";
      }
      $doc_block = $doc_block.$gen_from->render()."\n";
    }

    if (PartiallyGeneratedCode::containsManualSection($content)) {
      $builder->addDocBlock(
        PartiallyGeneratedSignedSource::getDocBlock($doc_block)
      );
      $builder->add($content);

      $code = $builder->getCode();
      $partial = new PartiallyGeneratedCode($code);
      if ($old_content !== null) {
        $code = $partial->merge($old_content, $this->rekey);
      } else {
        $partial->assertValidManualSections();
      }
      return PartiallyGeneratedSignedSource::signFile($code);

    } else {
      $builder->addDocBlock(SignedSource::getDocBlock($doc_block));
      $builder->add($content);
      return SignedSource::signFile($builder->getCode());
    }
  }

  /**
   * Use this to skip reading in the existing file.
   * Only use when you're sure you're okay with blowing away the previous file.
   */
  public function setDoClobber(bool $do_force): this {
    $this->doClobber = $do_force;
    return $this;
  }

  private function getContent(): string {
    $builder = (new HackBuilder($this->config));
    $builder->addLineIf(
      $this->fileNamespace !== null,
      'namespace %s;',
      $this->fileNamespace,
    );
    foreach ($this->useNamespaces as $ns => $as) {
      $builder->addLine($as === null ? "use $ns;" : "use $ns as $as;");
    }

    foreach ($this->beforeTypes as $type) {
      $builder->ensureNewLine()->newLine();
      $builder->add($type->render());
    }
    foreach ($this->functions as $function) {
      $builder->ensureNewLine()->newLine();
      $builder->add($function->render());
    }
    foreach ($this->classes as $class) {
      $builder->ensureNewLine()->newLine();
      $builder->add($class->render());
    }

    foreach ($this->traits as $trait) {
      $builder->ensureNewLine()->newLine();
      $builder->add($trait->render());
    }

    foreach ($this->afterTypes as $type) {
      $builder->ensureNewLine()->newLine();
      $builder->add($type->render());
    }
    return $builder->getCode();
  }

  private function loadExistingFiles(): ?string {
    $file_names = $this->otherFileNames;
    $file_names[] = $this->fileName;
    $all_content = array();
    foreach ($file_names as $file_name) {
      if (file_exists($file_name)) {
        $content = Filesystem::readFile($file_name);
        if ($content) {
          $root_dir = $this->config->getRootDir();
          $relative_path = Str::startsWith($file_name, $root_dir)
            ? Str::substr($file_name, Str::len($root_dir) + 1)
            : $file_name;

          if (!$this->doClobber) {
            if (!SignedSourceBase::isSignedByAnySigner($content)) {
              throw new CodegenFileNoSignatureException($relative_path);
            }
            if (!SignedSourceBase::hasValidSignatureFromAnySigner($content)) {
              throw new CodegenFileBadSignatureException($relative_path);
            }
          }
        }
        $all_content[] = $content;
      }
    }
    return implode('', $all_content);
  }

  public function setGeneratedFrom(
    CodegenGeneratedFrom $from
  ): this {
    $this->generatedFrom = $from;
    return $this;
  }

  public function setNamespace(string $file_namespace): this {
    invariant($this->fileNamespace === null, 'namespace has already been set');
    $this->fileNamespace = $file_namespace;
    return $this;
  }

  public function useNamespace(string $ns, ?string $as = null): this {
    invariant(
      !$this->useNamespaces->contains($ns),
      $ns.' is already being used',
    );
    $this->useNamespaces[$ns] = $as;
    return $this;
  }

  public function useClass(string $ns, ?string $as = null): this {
    return $this->useNamespace($ns, $as);
  }

  public function useFunction(string $ns, ?string $as = null): this {
    return $this->useNamespace('function '.$ns, $as);
  }

  public function useConst(string $ns, ?string $as = null): this {
    return $this->useNamespace('const '.$ns, $as);
  }

  /**
   * If called, save() will only write the file if it doesn't exist
   */
  public function createOnly(): this {
    $this->createOnly = true;
    return $this;
  }

  /**
   * Saves the generated file.
   *
   * @return CodegenFileResultType
   */
  public function save(): CodegenFileResult {
    Filesystem::createDirectory(
      substr($this->fileName, 0, strrpos($this->fileName, '/')),
      0777,
    );
    $is_creating = !file_exists($this->fileName);
    if (!$is_creating && $this->createOnly) {
      return CodegenFileResult::NONE;
    }
    $changed = Filesystem::writeFileIfChanged(
      $this->fileName,
      $this->render(),
    );
    return $is_creating
      ? CodegenFileResult::CREATE
      : ($changed ? CodegenFileResult::UPDATE : CodegenFileResult::NONE);
  }
}

abstract class CodegenFileSignatureException extends \Exception {

  public function __construct(
    string $message,
    private string $fileName,
  ) {
    parent::__construct($message);
  }

  public function getFileName(): string {
    return $this->fileName;
  }
}

final class CodegenFileBadSignatureException
  extends CodegenFileSignatureException {

  public function __construct(string $file_name) {
    $message = sprintf(
      'The signature of the existing generated file \'%s\' is invalid',
      $file_name,
    );
    parent::__construct($message, $file_name);
  }
}

final class CodegenFileNoSignatureException
  extends CodegenFileSignatureException {

  public function __construct(string $file_name) {
    $message = sprintf(
      'The existing generated file \'%s\' does not have a signature',
      $file_name,
    );
    parent::__construct($message, $file_name);
  }
}
