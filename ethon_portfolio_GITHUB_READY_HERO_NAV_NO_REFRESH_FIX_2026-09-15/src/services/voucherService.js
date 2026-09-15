/** Voucher application boundary. Generation is injected to keep policy testable. */
export default class VoucherService {
  constructor({ generator } = {}) {
    if (typeof generator !== 'function') throw new TypeError('VoucherService requires a generator');
    this.generator = generator;
  }

  create(payload) { return this.generator(payload); }
}
