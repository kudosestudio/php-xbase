<?php declare(strict_types=1);

namespace XBase;

use XBase\Column\ColumnInterface;
use XBase\Enum\TableType;
use XBase\Header\Writer\HeaderWriterFactory;
use XBase\Memo\MemoFactory;
use XBase\Memo\MemoInterface;
use XBase\Memo\WritableMemoInterface;
use XBase\Record\RecordFactory;
use XBase\Record\RecordInterface;
use XBase\Stream\Stream;
use XBase\Traits\CloneTrait;

class WritableTable extends Table
{
    use CloneTrait;

    /**
     * Perform any edits on clone file and replace original file after call `save` method.
     */
    public const EDIT_MODE_CLONE = 'clone';

    /**
     * Perform edits immediately on original file.
     */
    public const EDIT_MODE_REALTIME = 'realtime';

    /**
     * @var bool record property is new
     */
    private $insertion = false;

    protected function resolveOptions($options, $convertFrom = null): array
    {
        return array_merge(
            ['editMode' => self::EDIT_MODE_CLONE],
            parent::resolveOptions($options, $convertFrom)
        );
    }

    protected function open(): void
    {
        switch ($this->options['editMode']) {
            case self::EDIT_MODE_CLONE:
                $this->clone();
                $this->fp = Stream::createFromFile($this->cloneFilepath, 'rb+');
                break;

            case self::EDIT_MODE_REALTIME:
                $this->fp = Stream::createFromFile($this->filepath, 'rb+');
                break;
        }
    }

    protected function openMemo(): void
    {
        if (TableType::hasMemo($this->getVersion())) {
            $memoOptions = array_merge($this->options, ['writable' => true]);
            $this->memo = MemoFactory::create($this, $memoOptions);
        }
    }

    public function close(): void
    {
        parent::close();

        if ($this->cloneFilepath && file_exists($this->cloneFilepath)) {
            unlink($this->cloneFilepath);
        }
    }

    /**
     * @return WritableMemoInterface|null
     */
    public function getMemo(): ?MemoInterface
    {
        return $this->memo;
    }

    public function create($filename, $fields)
    {
    }

    protected function writeHeader(): void
    {
        HeaderWriterFactory::create($this->fp)->write($this->header);
    }

    public function appendRecord(): RecordInterface
    {
        $this->recordPos = $this->header->recordCount;
        $this->record = RecordFactory::create($this, $this->recordPos);
        $this->insertion = true;

        return $this->record;
    }

    public function writeRecord(RecordInterface $record = null): self
    {
        $record = $record ?? $this->record;
        if (!$record) {
            return $this;
        }

        $offset = $this->header->length + ($record->getRecordIndex() * $this->header->recordByteLength);
        $this->fp->seek($offset);
        $this->fp->write(RecordFactory::createDataConverter($this)->toBinaryString($record));

        if ($this->insertion) {
            $this->header->recordCount++;
        }

        $this->fp->flush();

        if (self::EDIT_MODE_REALTIME === $this->options['editMode'] && $this->insertion) {
            $this->save();
        }

        $this->insertion = false;

        return $this;
    }

    public function deleteRecord(?RecordInterface $record = null): self
    {
        if ($this->record && $this->insertion) {
            $this->record = null;
            $this->recordPos = -1;

            return $this;
        }

        $record = $record ?? $this->record;
        if (!$record) {
            return $this;
        }

        $record->setDeleted(true);
        $this->writeRecord($record);

        return $this;
    }

    public function undeleteRecord(?RecordInterface $record = null): self
    {
        $record = $record ?? $this->record;
        if (!$record || false === $record->isDeleted()) {
            return $this;
        }

        $record->setDeleted(false);

        $this->fp->seek($this->header->length + ($record->getRecordIndex() * $this->header->recordByteLength));
        $this->fp->write(' ');
        $this->fp->flush();

        return $this;
    }

    /**
     * Remove deleted records.
     */
    public function pack(): self
    {
        $newRecordCount = 0;
        for ($i = 0; $i < $this->getRecordCount(); $i++) {
            $r = $this->moveTo($i);

            if ($r->isDeleted()) {
                // remove memo columns
                foreach ($this->getMemoColumns() as $column) {
                    if ($pointer = $this->record->getGenuine($column->getName())) {
                        $this->getMemo()->delete($pointer);
                    }
                }
                continue;
            }

            $r->setRecordIndex($newRecordCount++);
            $this->writeRecord($r);
        }

        $this->header->recordCount = $newRecordCount;

        $size = $this->header->length + ($newRecordCount * $this->header->recordByteLength);
        $this->fp->truncate($size);

        if (self::EDIT_MODE_REALTIME === $this->options['editMode']) {
            $this->save();
        }

        return $this;
    }

    public function save(): self
    {
        if ($this->memo) {
            $this->memo->save();
        }

        $this->writeHeader();
        //check end-of-file marker
        $stat = $this->fp->stat();
        $this->fp->seek($stat['size'] - 1);
        if (self::END_OF_FILE_MARKER !== ($lastByte = $this->fp->readUChar())) {
            $this->fp->writeUChar(self::END_OF_FILE_MARKER);
        }

        if (self::EDIT_MODE_CLONE === $this->options['editMode']) {
            copy($this->cloneFilepath, $this->filepath);
        }

        return $this;
    }

    /**
     * @internal
     *
     * @todo Find better solution to notify table from Memo.
     */
    public function onMemoBlocksDelete(array $blocks): void
    {
        $columns = $this->getMemoColumns();

        for ($i = 0; $i < $this->header->recordCount; $i++) {
            $record = $this->pickRecord($i);
            $save = false;
            foreach ($columns as $column) {
                if (!$pointer = $record->getGenuine($column->getName())) {
                    continue;
                }

                $sub = 0;
                foreach ($blocks as $deletedPointer => $length) {
                    if ($pointer && $pointer > $deletedPointer) {
                        $sub += $length;
                    }
                }
                $save = $sub > 0;
                $record->setGenuine($column->getName(), $pointer - $sub);
            }
            if ($save) {
                $this->writeRecord($record);
            }
        }
    }

    /**
     * @return ColumnInterface[]
     */
    private function getMemoColumns(): array
    {
        $result = [];
        foreach ($this->getColumns() as $column) {
            if (in_array($column->getType(), TableType::getMemoTypes($this->header->getVersion()))) {
                $result[] = $column;
            }
        }

        return $result;
    }
}
