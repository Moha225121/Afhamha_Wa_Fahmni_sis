(() => {
    const replacements = new Map([
        ['افهمها وفهمني', 'مدرسة النسبية'],
        ['مدرسة النصر الدائم', 'مدرسة النسبية'],
        ['النصر الدائم', 'النسبية'],
        ['nasr-daem-logo.jpg', 'nasbia-logo.jpg'],
    ]);

    const replaceText = (value) => {
        for (const [from, to] of replacements) value = value.replaceAll(from, to);
        return value;
    };

    const applyIdentity = () => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => { node.nodeValue = replaceText(node.nodeValue); });
        document.querySelectorAll('[alt], [aria-label], [content]').forEach((element) => {
            ['alt', 'aria-label', 'content'].forEach((attribute) => {
                if (element.hasAttribute(attribute)) element.setAttribute(attribute, replaceText(element.getAttribute(attribute)));
            });
        });
        document.title = replaceText(document.title);
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyIdentity);
    else applyIdentity();
})();
