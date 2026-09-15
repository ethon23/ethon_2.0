/** Payment application boundary. Provider credentials/URLs remain external config. */
export default class PaymentService {
  constructor({ gatewayConfig = [] } = {}) {
    this.gatewayConfig = Object.freeze([...gatewayConfig]);
  }

  getGatewayConfig() { return [...this.gatewayConfig]; }
}
