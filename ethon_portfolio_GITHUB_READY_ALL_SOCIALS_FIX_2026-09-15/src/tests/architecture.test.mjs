import assert from 'node:assert/strict';
import CmsRepository from '../repositories/cmsRepository.js';
import CmsService from '../services/cmsService.js';
import PaymentService from '../services/paymentService.js';
import VoucherService from '../services/voucherService.js';

const adapter = {
  value: { ok: true },
  async getState() { return this.value; },
  async saveState(value) { this.value = value; return value; }
};

const cms = new CmsService(new CmsRepository(adapter));
assert.deepEqual(await cms.getState(), { ok: true });
assert.deepEqual(await cms.saveState({ clean: true }), { clean: true });

const gateways = ['STRIPE', 'PAYPAL', 'PAYONEER', 'GOOGLE PAY', 'BINANCE PAY', 'BKASH'];
const payments = new PaymentService({ gatewayConfig: gateways });
assert.deepEqual(payments.getGatewayConfig(), gateways);

const voucher = new VoucherService({ generator: payload => ({ currency: payload.currency }) });
assert.deepEqual(voucher.create({ currency: 'EUR' }), { currency: 'EUR' });

console.log('Architecture primitive tests: PASS');
