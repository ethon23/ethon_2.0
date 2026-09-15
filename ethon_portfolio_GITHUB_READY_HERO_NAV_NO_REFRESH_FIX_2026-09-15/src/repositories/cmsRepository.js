/** Persistence boundary for CMS state. */
export default class CmsRepository {
  constructor(adapter) {
    if (!adapter || typeof adapter.getState !== 'function' || typeof adapter.saveState !== 'function') {
      throw new TypeError('CmsRepository requires getState/saveState adapter methods');
    }
    this.adapter = adapter;
  }

  async getState() { return this.adapter.getState(); }
  async saveState(state) { return this.adapter.saveState(state); }
}
