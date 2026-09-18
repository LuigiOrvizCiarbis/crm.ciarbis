import * as XLSX from "xlsx"

export type ImportWorkbook = {
  workbook: XLSX.WorkBook | null
  sheetName: string
  file: File
  headers: string[]
  rows: string[][]
}

/** Reads CSV/XLSX consistently for every entity importer. TXT is intentionally unsupported. */
export async function readImportFile(selected: File, sheetName?: string): Promise<ImportWorkbook> {
  if (!/\.(csv|xlsx)$/i.test(selected.name)) {
    throw new Error("Solo se aceptan archivos CSV o XLSX")
  }
  if (selected.size > 10 * 1024 * 1024) {
    throw new Error("El archivo no puede superar los 10 MB")
  }

  let workbook: XLSX.WorkBook
  let originalName = selected.name
  if (/\.xlsx$/i.test(selected.name)) {
    workbook = XLSX.read(await selected.arrayBuffer(), { type: "array" })
  } else {
    workbook = XLSX.read(await selected.text(), { type: "string" })
  }

  const activeSheet = sheetName || workbook.SheetNames[0]
  if (!activeSheet || !workbook.Sheets[activeSheet]) {
    throw new Error("El archivo no contiene una hoja válida")
  }

  const csv = XLSX.utils.sheet_to_csv(workbook.Sheets[activeSheet])
  const matrix = XLSX.utils.sheet_to_json<string[]>(XLSX.read(csv, { type: "string" }).Sheets.Sheet1 || XLSX.utils.aoa_to_sheet([]), {
    header: 1,
    defval: "",
  }).map((row) => row.map(String))
  if (!matrix.length) throw new Error("El archivo no contiene filas válidas")

  const csvName = originalName.replace(/\.[^.]+$/, "") + ".csv"
  return {
    workbook: workbook.SheetNames.length > 1 ? workbook : null,
    sheetName: activeSheet,
    file: new File([csv], csvName, { type: "text/csv" }),
    headers: matrix[0] || [],
    rows: matrix.slice(1),
  }
}
