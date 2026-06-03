<?php

namespace  Opengenea\Gedcom;

class Parser
{
    private string $filePath;
    private array $callbacks = [];
    private ?array $currentRecord = null;
    private ?string $currentTopLevelTag = null;
    private array $pointerStack = [];

    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Bestand niet gevonden: {$filePath}");
        }
        $this->filePath = $filePath;
    }

    /**
     * Registreer een callback voor een specifieke top-level tag (bijv. INDI, FAM).
     */
    public function on(string $tag, callable $callback): void
    {
        $this->callbacks[$tag] = $callback;
    }

    /**
     * Start het parseren van het bestand regel voor regel.
     */
    public function parse(): void
    {
        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Kon bestand niet openen: {$this->filePath}");
        }

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if (empty($line)) {
                continue;
            }

            $this->parseLine($line);
        }

        // Trigger de allerlaatste record in het bestand
        $this->triggerCurrentRecord();

        fclose($handle);
    }

    /**
     * Verwerkt één enkele GEDCOM-regel.
     */
    private function parseLine(string $line): void
    {
        // Match GEDCOM patroon: <level> <tag|xref> [<arguments>]
        if (!preg_match('/^(\d+)\s+(?:@([^@]+)@\s+)?(\w+)(?:\s+(.*))?$/', $line, $matches)) {
            return;
        }

        $level = (int)$matches[1];
        $xref  = $matches[2] !== '' ? $matches[2] : null;
        $tag   = $matches[3];
        $value = $matches[4] ?? null;

        // Soms wisselt GEDCOM de tag en xref om bij top-level records (bijv. "0 @I1@ INDI")
        if ($level === 0 && $value !== null && $xref === null && preg_match('/^@([^@]+)@$/', $tag, $tagMatches)) {
            $xref = $tagMatches[1];
            $tag = $value;
            $value = null;
        }

        if ($level === 0) {
            // Verwerk vorig record voordat we een nieuwe starten
            $this->triggerCurrentRecord();

            // Start nieuw top-level record
            $this->currentTopLevelTag = $tag;
            $this->currentRecord = [];
            $this->pointerStack = [0 => &$this->currentRecord];

            if ($xref !== null) {
                $this->currentRecord[$tag][$xref] = [];
                $this->pointerStack[1] = &$this->currentRecord[$tag][$xref];
            } else {
                $this->currentRecord[$tag] = $value;
                $this->pointerStack[1] = &$this->currentRecord[$tag];
            }
        } else {
            // Alleen data opslaan als we luisteren naar deze top-level tag
            if (!isset($this->callbacks[$this->currentTopLevelTag])) {
                return;
            }

            // Zorg dat de stack-pointer op het juiste niveau staat
            if (!isset($this->pointerStack[$level])) {
                return; // Beveiliging tegen ongeldige GEDCOM structuur
            }

            // Bepaal de data-structuur voor dit niveau
            if ($xref !== null) {
                $data = ['_xref' => $xref];
                if ($value !== null) {
                    $data['_value'] = $value;
                }
            } else {
                $data = $value;
            }

            // Voeg data toe aan het bovenliggende niveau
            if (!isset($this->pointerStack[$level][$tag])) {
                $this->pointerStack[$level][$tag] = $data;
                $this->pointerStack[$level + 1] = &$this->pointerStack[$level][$tag];
            } else {
                // Als de tag al bestaat, verander het in een array van records (bijv. meerdere CHIL tags)
                if (!is_array($this->pointerStack[$level][$tag]) || isset($this->pointerStack[$level][$tag]['_xref']) || isset($this->pointerStack[$level][$tag]['_value'])) {
                    $this->pointerStack[$level][$tag] = [$this->pointerStack[$level][$tag]];
                }
                $this->pointerStack[$level][$tag][] = $data;
                $this->pointerStack[$level + 1] = &$this->pointerStack[$level][$tag][count($this->pointerStack[$level][$tag]) - 1];
            }
        }
    }

    /**
     * Voert de callback uit voor het huidige record indien geregistreerd.
     */
    private function triggerCurrentRecord(): void
    {
        if ($this->currentTopLevelTag && isset($this->callbacks[$this->currentTopLevelTag])) {
            $this->callbacks[$this->currentTopLevelTag]($this->currentRecord);
        }
        $this->currentRecord = null;
        $this->currentTopLevelTag = null;
        $this->pointerStack = [];
    }
}
