/** HTTP adapter for CMS use-cases. */
export default class CmsController {
  constructor(service) { this.service = service; }
  async get(req, res, next) {
    try { return res.json(await this.service.getState()); }
    catch (error) { return next(error); }
  }
  async save(req, res, next) {
    try { return res.json(await this.service.saveState(req.body)); }
    catch (error) { return next(error); }
  }
}
