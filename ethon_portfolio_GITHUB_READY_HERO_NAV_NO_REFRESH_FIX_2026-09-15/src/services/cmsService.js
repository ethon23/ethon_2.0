/** Application service for CMS state operations. */
export default class CmsService {
  constructor(repository) {
    if (!repository) throw new TypeError('CmsService requires a repository');
    this.repository = repository;
  }

  async getState() { return this.repository.getState(); }
  async saveState(state) { return this.repository.saveState(state); }
}
