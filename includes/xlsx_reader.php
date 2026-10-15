<?php

/**
 * PEMBACA FILE XLSX SEDERHANA — TANPA LIBRARY/COMPOSER EKSTERNAL.
 *
 * File .xlsx sebenarnya adalah arsip ZIP berisi beberapa file XML.
 * Fungsi di bawah ini membaca sheet PERTAMA saja dari file .xlsx,
 * memakai ekstensi PHP bawaan: ZipArchive + SimpleXML.
 *
 * Keterbatasan (disengaja, agar tetap ringan & tanpa dependency):
 * - Hanya membaca sheet pertama (cukup untuk kebutuhan template import siswa).
 * - Tidak mendukung rumus (hanya mengambil nilai/teks apa adanya).
 * - Tidak mendukung format .xls lama (Excel 97-2003) — harus .xlsx.
 */

/**
 * Baca semua baris dari sheet pertama sebuah file .xlsx.
 * Return: array baris, tiap baris = array asosiatif [indeks_kolom => nilai]
 * dengan indeks_kolom dimulai dari 0 (A=0, B=1, dst), baris kosong di antara
 * data tetap direpresentasikan sebagai array kosong agar urutan baris terjaga.
 *
 * @throws RuntimeException jika file tidak valid / bukan xlsx / ekstensi PHP tidak tersedia
 */
function read_xlsx_first_sheet(string $filepath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP "zip" tidak aktif di server. Aktifkan ekstensi zip & xml, atau gunakan format CSV.');
    }
    if (!class_exists('SimpleXMLElement')) {
        throw new RuntimeException('Ekstensi PHP "simplexml" tidak aktif di server. Aktifkan ekstensi zip & xml, atau gunakan format CSV.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new RuntimeException('File .xlsx tidak dapat dibuka (file rusak atau bukan xlsx yang valid).');
    }

    // 1. Baca tabel shared strings (teks yang dipakai berulang disimpan terpusat oleh Excel)
    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = @simplexml_load_string($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                // Teks bisa langsung di <t>, atau terpecah dalam beberapa <r><t>
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // 2. Cari nama sheet pertama dari workbook.xml + workbook.xml.rels
    $sheetPath = 'xl/worksheets/sheet1.xml'; // fallback default
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $rId = (string) $wb->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rId) {
                    $target = (string) $rel['Target'];
                    if (strpos($target, '/') === 0) {
                        // Target absolut dari root arsip, contoh: "/xl/worksheets/sheet1.xml"
                        $sheetPath = ltrim($target, '/');
                    } else {
                        // Target relatif terhadap folder "xl/", contoh: "worksheets/sheet1.xml"
                        $sheetPath = 'xl/' . $target;
                    }
                    break;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('Sheet pertama tidak ditemukan di dalam file .xlsx.');
    }
    $zip->close();

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Gagal membaca isi sheet .xlsx (XML tidak valid).');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $cellRef = (string) $cell['r'];      // contoh: "C5"
            $colLetters = preg_replace('/[0-9]/', '', $cellRef);
            $colIndex = xlsx_column_letter_to_index($colLetters);

            $type = (string) $cell['t'];
            $rawValue = isset($cell->v) ? (string) $cell->v : '';

            if ($type === 's') { // shared string
                $value = $sharedStrings[(int) $rawValue] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else { // angka, tanggal (serial number), atau kosong
                $value = $rawValue;
            }

            $rowData[$colIndex] = $value;
        }

        // Isi celah kolom kosong (mis. kolom B kosong) supaya index tetap berurutan 0..n
        if (!empty($rowData)) {
            $maxCol = max(array_keys($rowData));
            $normalized = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $normalized[$i] = $rowData[$i] ?? '';
            }
            $rows[] = $normalized;
        } else {
            $rows[] = [];
        }
    }

    return $rows;
}

/**
 * Konversi huruf kolom Excel (A, B, ..., Z, AA, AB, ...) ke index 0-based.
 */
function xlsx_column_letter_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

/**
 * Jika kolom "Tanggal Lahir" di Excel diformat sebagai tipe Tanggal (bukan Teks),
 * Excel menyimpan nilainya sebagai ANGKA SERIAL (jumlah hari sejak 30-12-1899),
 * bukan string "2009-05-05". Fungsi ini mendeteksi & mengonversinya ke format
 * Y-m-d. Jika nilai sudah berupa teks tanggal biasa, dikembalikan apa adanya.
 */
function xlsx_normalize_possible_date(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    // Angka serial Excel yang wajar untuk tanggal lahir (kira-kira tahun 1920-2020-an)
    if (preg_match('/^\d+(\.\d+)?$/', $value)) {
        $serial = (float) $value;
        if ($serial > 0 && $serial < 60000) {
            try {
                $date = new DateTime('1899-12-30');
                $date->modify('+' . (int) $serial . ' days');
                return $date->format('Y-m-d');
            } catch (Exception $e) {
                return $value; // gagal konversi, kembalikan apa adanya
            }
        }
    }

    return $value; // sudah berupa teks tanggal (mis. "2009-05-05"), biarkan apa adanya
}
