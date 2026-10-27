<?php

namespace App\Database;

/**
 * Demo data for the Kiez Living shop. The product descriptions matter:
 * they are what the embeddings model reads to decide which products are similar.
 */
final class DemoCatalogue
{
    /** name, email, city */
    public const CUSTOMERS = [
        ['Lena Fischer', 'lena.fischer@example.com', 'Berlin'],
        ['Jonas Weber', 'jonas.weber@example.com', 'Hamburg'],
        ['Aylin Demir', 'aylin.demir@example.com', 'Köln'],
        ['Marco Rossi', 'marco.rossi@example.com', 'München'],
        ['Sophie Becker', 'sophie.becker@example.com', 'Leipzig'],
        ['Emre Yilmaz', 'emre.yilmaz@example.com', 'Frankfurt'],
    ];

    /** sku, name, category, color, material, price in cents, stock, description */
    public const PRODUCTS = [
        ['KL-1001', 'Linen Cushion Cover, sage', 'Textiles', 'sage green', 'linen', 2499, 42, 'Soft washed linen cushion cover in a calm sage green, 50x50 cm, with a hidden zip. Brings a natural, relaxed look to sofas and beds.'],
        ['KL-1002', 'Wool Throw Blanket, grey', 'Textiles', 'grey', 'wool', 6999, 8, 'Warm woven wool throw in soft grey with fringed edges, 130x170 cm. Perfect for cosy evenings on the sofa.'],
        ['KL-1003', 'Ceramic Vase, sand', 'Decoration', 'sand', 'ceramic', 3499, 15, 'Handmade ceramic vase with a matte sand glaze and an organic shape. Beautiful with dried flowers or on its own.'],
        ['KL-1004', 'Scented Candle, fig & cedar', 'Decoration', 'cream', 'soy wax', 1899, 120, 'Soy wax candle in a glass jar with a warm scent of fig and cedarwood. Burns for about 40 hours.'],
        ['KL-1005', 'Oak Picture Frame 30x40', 'Decoration', 'natural', 'oak', 2999, 0, 'Solid oak picture frame with a white passe-partout for 30x40 cm prints. Stands or hangs.'],
        ['KL-1006', 'Stoneware Mug Set (4)', 'Kitchen', 'white', 'stoneware', 3999, 25, 'Set of four handmade stoneware mugs with a speckled white glaze, 350 ml. Dishwasher safe.'],
        ['KL-1007', 'Bamboo Serving Board', 'Kitchen', 'natural', 'bamboo', 2799, 31, 'Large bamboo serving board with a handle, ideal for cheese, bread and antipasti.'],
        ['KL-1008', 'Rattan Floor Lamp', 'Lighting', 'natural', 'rattan', 14999, 4, 'Tall floor lamp with a hand-woven rattan shade that casts a warm, patterned light. Boho style.'],
        ['KL-1009', 'Glass Pendant Light', 'Lighting', 'clear', 'glass', 8999, 0, 'Minimalist pendant light with a clear glass globe and black fabric cable. Modern and airy.'],
        ['KL-1010', 'Jute Rug 160x230', 'Textiles', 'natural', 'jute', 12999, 6, 'Hand-braided natural jute rug, 160x230 cm. Durable, earthy texture for living rooms.'],
        ['KL-1011', 'Linen Cushion Cover, terracotta', 'Textiles', 'terracotta', 'linen', 2499, 30, 'Washed linen cushion cover in warm terracotta, 50x50 cm. Pairs well with natural and earthy tones.'],
        ['KL-1012', 'Linen Table Runner, sage', 'Textiles', 'sage green', 'linen', 2999, 18, 'Washed linen table runner in sage green, 40x150 cm. A relaxed, natural accent for the dining table.'],
        ['KL-1013', 'Chunky Knit Throw, cream', 'Textiles', 'cream', 'cotton', 7999, 5, 'Oversized chunky knit throw in cream cotton. Soft, heavy and very cosy for the sofa or bed.'],
        ['KL-1014', 'Velvet Cushion, olive', 'Textiles', 'olive green', 'velvet', 2999, 22, 'Soft cotton velvet cushion in olive green, 45x45 cm, with feather filling.'],
        ['KL-1015', 'Waffle Bath Towel Set, sand', 'Textiles', 'sand', 'cotton', 4499, 14, 'Set of two waffle-weave cotton bath towels in sand. Light, quick-drying and soft.'],
        ['KL-1016', 'Stoneware Vase, sage', 'Decoration', 'sage green', 'stoneware', 3999, 11, 'Tall stoneware vase with a sage green reactive glaze. Handmade, each piece is unique.'],
        ['KL-1017', 'Dried Pampas Bouquet', 'Decoration', 'beige', 'dried grass', 2499, 40, 'Fluffy bouquet of dried pampas grass in natural beige, about 70 cm. Lasts for years.'],
        ['KL-1018', 'Ceramic Candle Holder Set (3)', 'Decoration', 'sand', 'ceramic', 2299, 27, 'Set of three ceramic candle holders in matte sand for dinner candles. Different heights.'],
        ['KL-1019', 'Scented Candle, linen & musk', 'Decoration', 'white', 'soy wax', 1899, 64, 'Soy wax candle in a glass jar with a fresh scent of clean linen and white musk. About 40 hours.'],
        ['KL-1020', 'Round Wall Mirror, brass', 'Decoration', 'gold', 'brass', 8999, 7, 'Round wall mirror, 60 cm, with a thin brushed brass frame. Makes small rooms feel bigger.'],
        ['KL-1021', 'Oak Picture Frame 20x30', 'Decoration', 'natural', 'oak', 1999, 19, 'Solid oak picture frame for 20x30 cm prints with a white passe-partout. Stands or hangs.'],
        ['KL-1022', 'Stoneware Dinner Plates (4)', 'Kitchen', 'white', 'stoneware', 4999, 16, 'Set of four handmade stoneware dinner plates with a speckled white glaze, 27 cm.'],
        ['KL-1023', 'Linen Napkins, sage (4)', 'Kitchen', 'sage green', 'linen', 1999, 33, 'Set of four washed linen napkins in sage green, 45x45 cm. Soft and absorbent.'],
        ['KL-1024', 'Acacia Salad Bowl', 'Kitchen', 'natural', 'acacia wood', 3499, 12, 'Large salad bowl carved from acacia wood, 30 cm, with a warm natural grain.'],
        ['KL-1025', 'Glass Carafe with Cup', 'Kitchen', 'clear', 'glass', 2499, 20, 'Mouth-blown glass water carafe, 1 litre, with a matching cup that doubles as a lid.'],
        ['KL-1026', 'Enamel Coffee Pot, cream', 'Kitchen', 'cream', 'enamel', 3299, 0, 'Retro enamel coffee pot in cream for stovetop and induction, 1.2 litres.'],
        ['KL-1027', 'Rattan Pendant Lamp', 'Lighting', 'natural', 'rattan', 9999, 9, 'Hand-woven rattan pendant lamp, 45 cm, that casts warm patterned light over dining tables. Boho style.'],
        ['KL-1028', 'Ceramic Table Lamp, sand', 'Lighting', 'sand', 'ceramic', 7999, 6, 'Table lamp with a matte sand ceramic base and a linen shade. Soft, warm light for side tables.'],
        ['KL-1029', 'LED String Lights, warm white', 'Lighting', 'warm white', 'copper wire', 1999, 55, 'Ten metres of copper wire string lights with 100 warm white LEDs and a timer.'],
        ['KL-1030', 'Brass Reading Lamp', 'Lighting', 'gold', 'brass', 11999, 3, 'Adjustable reading lamp in brushed brass with a swivel arm. Classic and elegant.'],
        ['KL-1031', 'Seagrass Storage Basket', 'Storage', 'natural', 'seagrass', 3499, 24, 'Hand-woven seagrass basket with handles, 40 cm. For blankets, toys or plants.'],
        ['KL-1032', 'Rattan Laundry Basket', 'Storage', 'natural', 'rattan', 4999, 10, 'Tall hand-woven rattan laundry basket with a lid and a removable cotton liner.'],
        ['KL-1033', 'Linen Storage Box, sage', 'Storage', 'sage green', 'linen', 2499, 17, 'Foldable storage box covered in sage green linen, for shelves and wardrobes.'],
        ['KL-1034', 'Oak Wall Shelf 60 cm', 'Storage', 'natural', 'oak', 5999, 8, 'Solid oak wall shelf, 60 cm, with hidden brackets. Clean, Scandinavian look.'],
        ['KL-1035', 'Jute Doormat', 'Textiles', 'natural', 'jute', 2999, 26, 'Hand-braided natural jute doormat, 60x90 cm. Robust and earthy.'],
        ['KL-1036', 'Wool Cushion, grey', 'Textiles', 'grey', 'wool', 3499, 0, 'Knitted wool cushion in soft grey, 45x45 cm. Matches the grey wool throw.'],
    ];

    /** order number, customer email, status, days ago, tracking code, [sku => quantity] */
    public const ORDERS = [
        ['SO-1001', 'lena.fischer@example.com', 'delivered', 21, 'DHL-00340012345', ['KL-1001' => 2, 'KL-1004' => 1]],
        ['SO-1002', 'jonas.weber@example.com', 'shipped', 3, 'DHL-00340067890', ['KL-1008' => 1]],
        ['SO-1003', 'lena.fischer@example.com', 'processing', 1, null, ['KL-1002' => 1, 'KL-1003' => 1]],
        ['SO-1004', 'aylin.demir@example.com', 'pending', 0, null, ['KL-1006' => 1, 'KL-1007' => 2]],
        ['SO-1005', 'marco.rossi@example.com', 'cancelled', 9, null, ['KL-1009' => 2]],
        ['SO-1006', 'aylin.demir@example.com', 'delivered', 40, 'HERMES-H1029384', ['KL-1010' => 1]],
        ['SO-1007', 'jonas.weber@example.com', 'processing', 2, null, ['KL-1004' => 3, 'KL-1001' => 1]],
        ['SO-1008', 'sophie.becker@example.com', 'delivered', 12, 'DHL-00340099881', ['KL-1012' => 1, 'KL-1022' => 1]],
        ['SO-1009', 'emre.yilmaz@example.com', 'shipped', 4, 'DPD-01505512345', ['KL-1027' => 1, 'KL-1031' => 2]],
    ];
}
