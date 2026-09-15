/** Route composition boundary. Endpoint migration can happen route-by-route. */
export default function registerCmsRoutes(router, controller) {
  router.get('/api/public', controller.get.bind(controller));
  return router;
}
