/**
 * Imágenes del constructor. Meta no acepta URLs: van en base64 dentro del Flow JSON,
 * sólo JPEG o PNG. Se achican en el navegador para entrar en el límite de Meta.
 */

const PNG_PREFIX = "iVBORw0KGgo";
const JPEG_PREFIX = "/9j/";

/** Lado mayor con el que se guarda cada tipo de imagen. */
export const BLOCK_IMAGE_MAX_SIDE = 1200;
export const OPTION_IMAGE_MAX_SIDE = 400;

export class ImagePrepError extends Error {
  constructor(readonly reason: "unsupported" | "tooLarge") {
    super(reason);
  }
}

export function isSupportedImage(base64: string | null | undefined): base64 is string {
  return !!base64 && (base64.startsWith(PNG_PREFIX) || base64.startsWith(JPEG_PREFIX));
}

/** Bytes reales de una imagen en base64. */
export function imageBytes(base64: string): number {
  const padding = base64.endsWith("==") ? 2 : base64.endsWith("=") ? 1 : 0;
  return Math.floor((base64.length * 3) / 4) - padding;
}

export function imageDataUrl(base64: string): string {
  return `data:${base64.startsWith(PNG_PREFIX) ? "image/png" : "image/jpeg"};base64,${base64}`;
}

export function formatKb(bytes: number): string {
  return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(",", 2)[1] ?? "");
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

function encode(canvas: HTMLCanvasElement, type: "image/png" | "image/jpeg", quality?: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * Devuelve la imagen en base64 dentro de `maxBytes`. Un JPEG o PNG que ya entra se guarda
 * tal cual (conserva la transparencia); si no, se achica a `maxSide` y se prueba PNG (si el
 * original era PNG) y después JPEG con calidad y tamaño decrecientes, sobre fondo blanco.
 */
export async function prepareImage(file: File, maxBytes: number, maxSide: number): Promise<string> {
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    throw new ImagePrepError("unsupported");
  }

  try {
    const fitsAsIs =
      (file.type === "image/png" || file.type === "image/jpeg") &&
      file.size <= maxBytes &&
      Math.max(bitmap.width, bitmap.height) <= maxSide;
    if (fitsAsIs) return await blobToBase64(file);

    let scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    for (let attempt = 0; attempt < 6; attempt += 1) {
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      const context = canvas.getContext("2d");
      if (!context) throw new ImagePrepError("unsupported");

      if (file.type === "image/png") {
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const png = await encode(canvas, "image/png");
        if (png && png.size <= maxBytes) return await blobToBase64(png);
      }

      // JPEG no tiene transparencia: el fondo transparente quedaría negro.
      context.fillStyle = "#ffffff";
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      for (const quality of [0.85, 0.72, 0.6]) {
        const jpeg = await encode(canvas, "image/jpeg", quality);
        if (jpeg && jpeg.size <= maxBytes) return await blobToBase64(jpeg);
      }
      scale *= 0.75;
    }
    throw new ImagePrepError("tooLarge");
  } finally {
    bitmap.close();
  }
}
