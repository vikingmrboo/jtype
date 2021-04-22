#! /usr/bin/php
<?php
declare(strict_types=1);


use JetBrains\PhpStorm\Pure;

abstract class AbstractType
{
    public function __construct(private string $name, private bool $nullable, private bool $arrayOf)
    {
    }

    abstract public function isBase(): bool;

    public function toString(string $tabs = "\t"): string
    {
        $result = $this->getName();

        if ($this->isArrayOf()) {
            $result .= '[]';
        }

        if ($this->isNullable()) {
            $result = "null|{$result}";
        }

        return $result;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isArrayOf(): bool
    {
        return $this->arrayOf;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}


class BaseType extends AbstractType
{
    public function isBase(): bool
    {
        return true;
    }
}

class StructureType extends AbstractType
{
    private FileStructure $structure;
    private string $fullClassName;

    public function getFullClassName(): string
    {
        return $this->fullClassName;
    }


    public function setFullClassName(string $fullClassName): void
    {
        $this->fullClassName = $fullClassName;
    }

    public function isBase(): bool
    {
        return false;
    }

    public function getStructure(): FileStructure
    {
        return $this->structure;
    }

    public function setStructure(FileStructure $structure): void
    {
        $this->structure = $structure;
    }

    public function toString(string $tabs = ""): string
    {
        $result = parent::toString($tabs) . ': ';
        $structureStr = "{\n{$this->getStructure()->toString("{$tabs}\t")}{$tabs}}";
        if ($this->isArrayOf()) {
            $result .= "[$structureStr]";
        } else {
            $result .= $structureStr;
        }
        return $result;
    }
}

class FileStructure
{
    public function __construct(private array $structure)
    {
    }

    public function appendField(string $field, $type): void
    {
        $this->structure[$field] = $type;
    }

    public function getStructure(): array
    {
        return $this->structure;
    }

    public function toString(string $tabs = ""): string
    {
        $result = '';
        foreach ($this->getStructure() as $name => $type) {
            $result .= "{$tabs}\"{$name}\": {$type->toString("{$tabs}")}\n";
        }

        return $result;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

if (empty($argv[1])) {
    $currentDir = getcwd();
} else {
    if (!$currentDir = realpath($argv[1])) {
        file_put_contents('php://stderr', "Invalid path given {$argv[1]}" . PHP_EOL);
        exit(1);
    }
}

/**
 * @var FileStructure[] $structure
 */
$structure = [];

/**
 * @var AbstractType[] $tupes
 */
$types = [];

$detectBaseNamespcae = function (string $fqcn): string {
    throw new RuntimeException("Not initialized");
};

$getFileByClassName = function (string $fqcn) use (&$detectBaseNamespcae, &$getFileByClassName): string {
    $detectBaseNamespcae($fqcn);
    return $getFileByClassName($fqcn);
};

$getClassRelativeName = function (string $fqcn) use (&$detectBaseNamespcae, &$getClassRelativeName) {
    $detectBaseNamespcae($fqcn);
    return $getClassRelativeName($fqcn);
};

$detectBaseNamespcae = function (string $fqcn) use (&$getFileByClassName, $currentDir, &$getClassRelativeName): void {
    $classParts = explode('\\', $fqcn);
    $getNamespaceForFolder = function ($lastDir) use (&$classParts): ?string {
        while ($namespaceLevel = array_pop($classParts)) {

            if (0 === strcasecmp($lastDir, $namespaceLevel)) {
                return implode('\\', $classParts) . '\\' . $namespaceLevel;
            }
        }

        return null;
    };

    $match = (function () use ($currentDir, $getNamespaceForFolder): object {
        $match = null;
        do {
            $dirName = basename($currentDir);
            $matched = $getNamespaceForFolder($dirName);

            if (!$matched) {
                return $match
                    ?: throw new RuntimeException("No association found {$dirName}");
            }

            $match = new class($currentDir, $matched) {
                public function __construct(public string $path, public string $namespace)
                {
                }
            };
        } while (($currentDir = dirname($currentDir)) !== '.');

        throw new RuntimeException("Association failed");
    })();

    $namespaceLen = strlen($match->namespace);

    $getFileByClassName = function (string $fqcn) use ($match, $namespaceLen): string {
        $fileName = $match->path . DIRECTORY_SEPARATOR . str_replace(
                '\\',
                DIRECTORY_SEPARATOR,
                substr($fqcn, $namespaceLen + 1)
            ) . '.php';

        return realpath($fileName)
            ?: throw new RuntimeException("Failed to get file {$fileName} by type {$fqcn}");
    };

    $getClassRelativeName = fn(string $fqcn) => substr($fqcn, $namespaceLen + 1);
};


$getStructure = function (string $fileName)
use (
    $currentDir,
    &$getFileByClassName,
    &$getClassRelativeName,
    &$structure,
    &$types,
    &$getStructure,
): ?FileStructure {

    if (isset($structure[$fileName])) {
        return $structure[$fileName];
    }

    $continueRead = true;
    $onClose = function () {
    };

    ($handle = fopen($fileName, 'r'))
    || throw new \RuntimeException("Unable to open file {$fileName} ({$fileName})");

    $uses = [];

    $currentNamespace = null;

    $getClassFullName = function (string $class) use (&$currentNamespace, &$uses): string {
        if ('\\' === substr($class, 0, 1)) {
            return $class;
        }

        if (isset($uses[$class])) {
            return $uses[$class];
        }

        return "{$currentNamespace}\\{$class}";
    };

    $createFromPhpDoc = function (string $phpdocType)
    use (
        $getClassFullName,
        &$getClassRelativeName
    ): AbstractType {
        $paramType = preg_replace('@\?|(?:\|\s*null)|(?:null\s*\|)@', '', $phpdocType, 1, $isNullable);
        $paramType = preg_replace('@\[\]@', '', $paramType, 1, $isArray);
        $isArray = $isArray || 'array' === $paramType;

        if (in_array($paramType, ['int', 'string', 'object', 'bool', 'float', 'array', 'mixed'])) {
            return new BaseType($paramType, (bool)$isNullable, $isArray);
        }
        $fullName = $getClassFullName($paramType);
        $structure = new StructureType($getClassRelativeName($fullName), (bool)$isNullable, $isArray);
        $structure->setFullClassName($fullName);

        return $structure;
    };

    $processClass = function (string $line)
    use (
        &$currentNamespace,
        &$uses,
        &$structure,
        &$types,
        &$processLine,
        &$onClose,
        &$processClass,
        &$continueRead,
        &$getFileByClassName,
        &$getClassRelativeName,
        $createFromPhpDoc,
        $fileName,
        $getClassFullName,
        $getStructure
    ): void {
        if (!preg_match('@class\s+(\S+)(?:\s+extends\s+(\S+))?(?:\s+implements\s+\S+)?\s*[{]?\s*(?:[/]|$)@su', $line, $matched)) {
            return;
        }

        $className = $matched[1];
        $fullClassName = "{$currentNamespace}\\{$className}";
        $curStruct = [];

        $onClose = function () use ($fileName, $fullClassName, &$getClassRelativeName, &$structure, &$types, &$curStruct): void {
            $structure[$fileName] = new FileStructure($curStruct);
            $types[$fullClassName] = new StructureType($getClassRelativeName($fullClassName), false, false);
            $types[$fullClassName]->setFullClassName($fullClassName);
            $types[$fullClassName]->setStructure($structure[$fileName]);
        };

        $structure[$fileName] = // prevent recursion
            new FileStructure([]);
        $types[$fullClassName] = new StructureType($getClassRelativeName($fullClassName), false, false);
        $types[$fullClassName]->setFullClassName($fullClassName);
        $types[$fullClassName]->setStructure($structure[$fileName]);

        if (!empty($matched[2])) {
            $subClass = $getClassFullName($matched[2]);
            $subClassFile = $getFileByClassName($subClass);
            $curStruct = $getStructure($subClassFile)->getStructure();
        }

        $createParam = function (string $paramType)
        use (
            $createFromPhpDoc,
            &$getFileByClassName,
            &$getStructure
        ): AbstractType {
            $paramType = $createFromPhpDoc($paramType);

            if ($paramType instanceof StructureType) {
                $paramFile = $getFileByClassName($paramType->getFullClassName());
                $paramType->setStructure($getStructure($paramFile));
            }

            return $paramType;
        };

        $processParams = function (string $line)
        use (
            &$processLine,
            &$processParams,
            &$continueRead,
            $createParam,
            &$curStruct
        ) {
            if (preg_match('@\s+function\s+(\S+)\s*\(@su', $line)) {
                $continueRead = false; //stop file reading
                return;
            }

            if (preg_match('/@var\s*(\S+)/su', $line, $matched)) {
                try {
                    $paramType = $createParam($matched[1]);
                } catch (\Exception $ex) {
                    file_put_contents('php://stderr', "Failed to resolve model {$matched[1]}\n");
                    return;
                }

                $processLine = function (string $line) use ($paramType, &$processLine, &$processParams, &$curStruct): void {
                    if (preg_match('@^/\*\*@su', $line)) {
                        $processLine = $processParams;
                        return;
                    }

                    if (!preg_match('@(?:public|protected|private)\s*(?:\S++\s++)?\$(?P<name>[^;[:space:]]++)(?:\s*=.*?)?\s*;\s*$@su', $line, $matched)) {
                        return;
                    }

                    $curStruct[$matched['name']] = $paramType;
                    $processLine = $processParams;
                };

            } elseif (preg_match('@(?:public|protected|private)\s*(?:(?P<type>\S++)\s++)?\$(?P<name>[^;[:space:]]++)(?:\s*=.*?)?\s*;\s*$@su', $line, $matched)) {
                $curStruct[$matched['name']] = $createParam($matched['type'] ?: 'string');
            }
        };

        $processLine = $processParams;
    };

    $processUse = function (string $line) use (&$uses, &$processClass) {
        if (!preg_match('@use\s+(\S+)(?:\s+as\s+(\S+))?\s*;@su', $line, $matched)) {
            $processClass($line);
            return;
        }

        $fqcn = $matched[1];

        if (empty($matched[2])) {
            if (false !== ($pos = strrpos($fqcn, '\\'))) {
                $alias = substr($fqcn, $pos + 1);
            } else {
                $alias = $fqcn;
            }
        } else {
            $alias = $matched[2];
        }
        $uses[$alias] = $fqcn;
    };

    $processLine = function ($line) use (&$currentNamespace, &$processLine, $processUse) {
        if (!preg_match('@(?:\s|^)namespace\s+(\S+)\s*;@su', $line, $matched))
            return;

        $currentNamespace = $matched[1];
        $processLine = $processUse;
    };

    try {
        while (!feof($handle) && $continueRead) {
            $line = fgets($handle);
            if (false === $line) {
                if (!feof($handle)) {
                    throw new RuntimeException("Failed to read file {$fileName}");
                }
                continue;
            }
            if ('' === trim($line)) continue;
            $processLine($line);
        }
        $onClose();

        return $structure[$fileName] ?? null;
    } catch (\Exception $ex) {
        file_put_contents('php://stderr', $ex->getMessage() . PHP_EOL);
        exit(1);
    } finally {
        fclose($handle);
    }
};

$dir = new RecursiveDirectoryIterator($currentDir);
$iterator = new RecursiveIteratorIterator($dir);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $item->isDir() || $getStructure($item->getPathname());
}

foreach ($types as $type) echo "{$type}\n";