const SPREADSHEET_ID = '12t3mFC1fL85xP9TLs6SvQq9cANdxppNUIWUO3MPK0FA';
const SHEET_NAME = 'Заказы';
const WEBHOOK_SECRET = 'ВСТАВЬТЕ_ТОТ_ЖЕ_СЕКРЕТ_ЧТО_И_В_ORDER_CONFIG';

const EXPECTED_HEADERS = [
  '📆 ДАТА', '#️⃣ ЗАКАЗА', '🚦 СТАТУС', '🧑🏻 ФИО', '☎️ ТЕЛЕФОН',
  '📩 EMAIL', '🏙️ ГОРОД', '📍 АДРЕС', '📦 ВИД ДОСТАВКИ', '💬 КОММЕНТ.',
  '🎁 ТОВАР', '💵 СУММА', '🔢 ПРОМОКОД', '🚚 ТРЕК СДЭК', '💸 СКИДКА'
];

function jsonResponse(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

function doGet() {
  return jsonResponse({ ok: true, service: 'ORIGATE order webhook' });
}

function doPost(event) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);

  try {
    if (!event || !event.postData || !event.postData.contents) {
      throw new Error('Empty request');
    }

    const payload = JSON.parse(event.postData.contents);
    if (payload.secret !== WEBHOOK_SECRET) {
      throw new Error('Unauthorized');
    }
    if (JSON.stringify(payload.headers) !== JSON.stringify(EXPECTED_HEADERS)) {
      throw new Error('Header schema mismatch');
    }
    if (!Array.isArray(payload.row) || payload.row.length !== EXPECTED_HEADERS.length) {
      throw new Error('Order row must contain exactly 15 fields');
    }
    if (String(payload.order_number) !== String(payload.row[1])) {
      throw new Error('Order number mismatch');
    }

    const spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
    const sheet = spreadsheet.getSheetByName(SHEET_NAME) || spreadsheet.insertSheet(SHEET_NAME);
    const currentHeaders = sheet.getRange(1, 1, 1, EXPECTED_HEADERS.length).getDisplayValues()[0];
    const headerIsEmpty = currentHeaders.every(value => value === '');

    if (headerIsEmpty) {
      sheet.getRange(1, 1, 1, EXPECTED_HEADERS.length).setValues([EXPECTED_HEADERS]);
      sheet.setFrozenRows(1);
    } else if (JSON.stringify(currentHeaders) !== JSON.stringify(EXPECTED_HEADERS)) {
      throw new Error('Existing sheet headers do not match the required schema');
    }

    const lastRow = sheet.getLastRow();
    if (lastRow >= 2) {
      const duplicate = sheet.getRange(2, 2, lastRow - 1, 1)
        .createTextFinder(String(payload.order_number))
        .matchEntireCell(true)
        .findNext();
      if (duplicate) {
        return jsonResponse({ ok: true, duplicate: true, order_number: String(payload.order_number) });
      }
    }

    const targetRow = Math.max(2, lastRow + 1);
    sheet.getRange(targetRow, 2).setNumberFormat('@');
    sheet.getRange(targetRow, 5).setNumberFormat('@');
    sheet.getRange(targetRow, 13, 1, 2).setNumberFormat('@');
    sheet.getRange(targetRow, 1, 1, EXPECTED_HEADERS.length).setValues([payload.row]);
    SpreadsheetApp.flush();

    return jsonResponse({ ok: true, order_number: String(payload.order_number) });
  } catch (error) {
    return jsonResponse({ ok: false, error: String(error && error.message ? error.message : error) });
  } finally {
    lock.releaseLock();
  }
}

