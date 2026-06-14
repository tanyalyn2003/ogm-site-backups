/** Shared staff roster for Customer DB, Message Center, and callbacks. */
const OGM_STAFF = [
  'Tanya Wadkins (TW)',
  'Austen Parlett (AP)',
  'Brennan Binkley (BB)',
  'G Sedberry Olive (SO)',
  'G Hunter Olive (HO)',
];

function ogmStaffSelectOptions(selected) {
  const sel = String(selected || '');
  return OGM_STAFF.map((name) => {
    const on = sel && name === sel ? ' selected' : '';
    return `<option value="${name.replace(/"/g, '&quot;')}"${on}>${name}</option>`;
  }).join('');
}
